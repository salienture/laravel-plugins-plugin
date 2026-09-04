<?php

namespace Salienture\Plugins\Support;

use RuntimeException;
use ZipArchive;

/**
 * Security + structure inspection for uploaded plugin archives.
 *
 * Everything runs as a streaming pass over the zip's central directory
 * and entry streams - NOTHING is extracted to disk before this check
 * passes. Rejections carry a list of concrete issues.
 *
 * Checks performed:
 * - zip slip: traversal segments, absolute paths, drive letters
 * - symlink entries (unix mode attributes)
 * - extension allow-list, nested archives and sensitive files (.env, dumps)
 * - zip-bomb guards: max file count and total uncompressed size
 * - PHP content scan for obfuscation / webshell primitives
 * - structure: exactly one plugin root, required header fields
 */
class PluginUploadScanner
{
    /** Unix file-type bits; 0xA000 marks symlinks. */
    private const S_IFLNK = 0xA000;

    /**
     * The complete WordPress-style header structure every main plugin
     * file must declare. Missing any of these invalidates the upload.
     *
     * @var array<string, string>
     */
    private const REQUIRED_HEADERS = [
        'name' => 'Plugin Name',
        'description' => 'Description',
        'version' => 'Version',
        'pluginUri' => 'Plugin URI',
        'author' => 'Author',
        'authorUri' => 'Author URI',
        'license' => 'License',
        'textDomain' => 'Text Domain',
        'requiresPhp' => 'Requires PHP',
        'requiresLaravel' => 'Requires Laravel',
        'updateUri' => 'Update URI',
        'namespace' => 'Namespace',
        'pluginClass' => 'Plugin Class',
    ];

    /**
     * Inspect an archive completely without extracting it.
     *
     * @param  string  $zipPath  Absolute path to the uploaded archive.
     * @return Inspection Metadata of the validated archive.
     *
     * @throws RuntimeException When the file is not a readable zip.
     * @throws PluginRejected When any security or structure check fails.
     */
    public static function inspect(string $zipPath): Inspection
    {
        $zip = new ZipArchive;

        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new RuntimeException("Uploaded file is not a valid zip archive (code {$opened}).");
        }

        try {
            $issues = [];
            $entries = self::collectEntries($zip, $issues);
            $manifest = null;
            $prefix = '';
            $entryName = null;

            // Main plugin file first: everything else is secondary.
            if ($issues === []) {
                $prefix = self::detectPrefix($entries, $issues);
                $entryName = self::entryFileFor($prefix, $entries);

                if ($entryName === null) {
                    $issues[] = 'Main plugin file ("*-plugin.php") not found in archive.';
                } else {
                    $manifest = self::assertMainFile(
                        $zip,
                        $prefix,
                        $entryName,
                        $entries,
                        $issues,
                    );
                }
            }

            if ($issues === [] && $manifest !== null) {
                self::scanPhpFiles($zip, $prefix, $entries, $issues);
            }

            if ($issues !== []) {
                throw new PluginRejected($issues);
            }

            return new Inspection($prefix, $manifest);
        } finally {
            $zip->close();
        }
    }

    /**
     * Walk every entry applying name, extension, symlink and size rules.
     *
     * @param  array<int, string>  $issues  Accumulator for found issues.
     * @return list<string> Normalized, non-directory entry names.
     */
    private static function collectEntries(ZipArchive $zip, array &$issues): array
    {
        $maxFiles = (int) config('plugins.upload.max_files', 2000);
        $maxTotalBytes = ((int) config('plugins.upload.max_total_mb', 100)) * 1024 * 1024;

        $allowed = array_map(
            'strtolower',
            (array) config('plugins.upload.allowed_extensions', []),
        );

        $blockedArchives = array_map(
            'strtolower',
            (array) config('plugins.upload.blocked_archives', ['zip', 'rar', '7z', 'tar', 'gz', 'phar']),
        );

        $names = [];
        $totalSize = 0;
        $count = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));

            if (str_ends_with($name, '/')) {
                continue;
            }

            if (++$count > $maxFiles) {
                $issues[] = "Archive exceeds the maximum of {$maxFiles} files.";

                break;
            }

            if (
                str_contains($name, '../')
                || str_starts_with($name, '/')
                || preg_match('#^[A-Za-z]:#', $name) === 1
            ) {
                $issues[] = "Unsafe path in archive: [{$name}].";

                continue;
            }

            if (self::isSymlink($zip, $index)) {
                $issues[] = "Symlink entries are not allowed: [{$name}].";

                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (in_array($extension, $blockedArchives, true)) {
                $issues[] = "Nested archives are not allowed: [{$name}].";

                continue;
            }

            if (! in_array($extension, $allowed, true)) {
                $issues[] = "File type [.{$extension}] is not allowed: [{$name}].";

                continue;
            }

            if (preg_match('#(^|/)\.env(\.|$)#', $name) === 1 || str_ends_with($name, '.sql')) {
                $issues[] = "Sensitive files are not allowed: [{$name}].";

                continue;
            }

            $stat = $zip->statIndex($index);

            if ($stat !== false) {
                $totalSize += (int) $stat['size'];
            }

            if ($totalSize > $maxTotalBytes) {
                $issues[] = sprintf(
                    'Archive exceeds the maximum uncompressed size of %d MB.',
                    (int) config('plugins.upload.max_total_mb', 100),
                );

                break;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * Whether an entry carries unix symlink mode attributes.
     */
    private static function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        return (($attributes >> 16) & 0xF000) === self::S_IFLNK;
    }

    /**
     * Determine the common directory prefix holding the plugin
     * ("vendor/name/" or any nested root when packaged inside folders,
     * "" for flat archives).
     *
     * @param  list<string>  $entries
     * @param  array<int, string>  $issues
     */
    private static function detectPrefix(array $entries, array &$issues): string
    {
        $candidates = preg_grep('#^(?:(?!-plugin\.php$)[^/]+/)*[^/]+-plugin\.php$#', $entries) ?: [];

        if ($candidates === []) {
            $issues[] = 'Archive does not contain a "*-plugin.php" entry file.';

            return '';
        }

        $first = reset($candidates);
        $directory = dirname((string) $first);

        foreach ($candidates as $candidate) {
            if (dirname($candidate) !== $directory) {
                $issues[] = 'Archive contains multiple plugins or ambiguous layouts.';

                break;
            }
        }

        return $directory === '.' ? '' : rtrim($directory, '/').'/';
    }

    /**
     * The exact archive-relative entry file name for the given prefix.
     *
     * @param  list<string>  $entries
     */
    private static function entryFileFor(string $prefix, array $entries): ?string
    {
        foreach (preg_grep('#^'.preg_quote($prefix, '#').'[^/]+-plugin\.php$#', $entries) ?: [] as $entry) {
            return (string) $entry;
        }

        return null;
    }

    /**
     * Validate the MAIN plugin file against the complete required header
     * structure. Runs before anything else so invalid uploads fail fast
     * with precise reasons.
     *
     * @param  ZipArchive  $zip  Open archive.
     * @param  string  $prefix  Detected plugin directory prefix.
     * @param  string  $entryName  Archive-relative main file name.
     * @param  list<string>  $entries  All normalized entry names.
     * @param  array<int, string>  $issues  Accumulator for found issues.
     *
     * @throws RuntimeException When the entry cannot be read.
     */
    private static function assertMainFile(
        ZipArchive $zip,
        string $prefix,
        string $entryName,
        array $entries,
        array &$issues,
    ): PluginManifest {
        $contents = self::stream($zip, $entryName);

        if (! str_starts_with(ltrim($contents), '<?php')) {
            $issues[] = "Main plugin file [{$entryName}] is not valid PHP (missing <?php opener).";
        }

        $manifest = PluginManifest::fromContents('upload/pending', $contents, $entryName);

        // Every standard field must be present.
        foreach (self::REQUIRED_HEADERS as $key => $label) {
            if (! isset($manifest->headers[$key]) || trim($manifest->headers[$key]) === '') {
                $issues[] = "Main plugin file is missing the \"{$label}\" header.";
            }
        }

        if ($issues !== []) {
            return $manifest;
        }

        // Value sanity checks.
        if (preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.\-]+)?$/', $manifest->headers['version']) !== 1) {
            $issues[] = 'Version must look like "1.0.0".';
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $manifest->headers['namespace']) !== 1) {
            $issues[] = 'Namespace must be a PSR-4 style prefix like "Salienture\\Notes".';
        }

        if (preg_match('/^[a-z0-9\-_]+$/', $manifest->headers['textDomain']) !== 1) {
            $issues[] = 'Text Domain must be lowercase letters, digits, dashes or underscores.';
        }

        foreach (['pluginUri', 'authorUri', 'updateUri'] as $urlField) {
            if (filter_var($manifest->headers[$urlField], FILTER_VALIDATE_URL) === false
                || ! preg_match('#^https?://#i', (string) $manifest->headers[$urlField])) {
                $issues[] = "\"{$this->headerLabel($urlField)}\" must be a valid http(s) URL.";
            }
        }

        // Plugin Class must live inside the declared Namespace and its
        // file must exist under the plugin's src/ directory in the archive.
        $namespace = rtrim($manifest->headers['namespace'], '\\');
        $class = ltrim((string) $manifest->pluginClass(), '\\');
        $prefixNs = $namespace.'\\';

        if (! str_starts_with($class, $prefixNs)) {
            $issues[] = "Plugin Class [{$class}] must live inside the Namespace [{$namespace}].";
        } else {
            $relativeClassPath = str_replace(
                '\\',
                '/',
                substr($class, strlen($prefixNs)),
            ).'.php';

            $expectedEntry = $prefix.'src/'.$relativeClassPath;

            if (! in_array($expectedEntry, $entries, true)) {
                $issues[] = "Class file [src/{$relativeClassPath}] was not found in the archive.";
            }
        }

        return $manifest;
    }

    /**
     * Human label for a normalized header key.
     */
    private static function headerLabel(string $key): string
    {
        return self::REQUIRED_HEADERS[$key] ?? $key;
    }

    /**
     * Stream every PHP file under the plugin prefix through the blocked
     * pattern list from configuration.
     *
     * @param  list<string>  $entries
     * @param  array<int, string>  $issues
     */
    private static function scanPhpFiles(ZipArchive $zip, string $prefix, array $entries, array &$issues): void
    {
        $patterns = array_map(
            fn (string $pattern): string => '#'.trim($pattern).'#i',
            (array) config('plugins.upload.blocked_patterns', []),
        );

        if ($patterns === []) {
            return;
        }

        foreach (preg_grep('#^'.preg_quote($prefix, '#').'.+\.php$#', $entries) ?: [] as $entry) {
            $contents = self::stream($zip, (string) $entry);

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $contents) === 1) {
                    $label = trim($pattern, '#i');

                    $issues[] = sprintf(
                        'Suspicious code [%s] found in [%s].',
                        $label,
                        $entry,
                    );
                }
            }
        }
    }

    /**
     * Read one entry's contents fully into a string.
     */
    private static function stream(ZipArchive $zip, string $name): string
    {
        $stream = $zip->getStream($name);

        if ($stream === false) {
            throw new RuntimeException("Could not read [{$name}] from the archive.");
        }

        $contents = stream_get_contents($stream) ?: '';

        fclose($stream);

        return $contents;
    }
}
