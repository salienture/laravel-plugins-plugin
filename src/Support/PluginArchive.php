<?php

namespace Salienture\Plugins\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Safe zip handling for plugin archives (uploads and update downloads).
 *
 * Accepted layouts: plugin files at the archive root, or a single
 * top-level directory containing them (GitHub-style zips).
 */
final class PluginArchive
{
    /**
     * Extract a plugin archive and locate the directory holding the
     * "*-plugin.php" entry file.
     *
     * @param  string  $zipPath  Absolute path to the archive.
     * @param  string  $destination  Empty directory to extract into.
     * @return string Absolute path of the detected plugin root directory.
     *
     * @throws RuntimeException On unreadable archives, unsafe entries
     *                          (zip slip) or when no plugin is found.
     */
    public static function extract(string $zipPath, string $destination): string
    {
        $zip = new ZipArchive;

        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new RuntimeException("Could not open archive [{$zipPath}] (code {$opened}).");
        }

        self::assertSafeEntries($zip);

        $zip->extractTo($destination);
        $zip->close();

        return self::locatePluginRoot($destination);
    }

    /**
     * Extract only the entries under the given archive prefix into the
     * destination directory, stripping the prefix so files land directly
     * at the plugin location.
     *
     * @param  string  $zipPath  Absolute path to a validated archive.
     * @param  string  $destination  Plugin target directory (created).
     * @param  string  $prefix  Archive prefix ("vendor/name/" or "").
     *
     * @throws RuntimeException On unreadable archives or unsafe entries.
     */
    public static function extractToDirectory(string $zipPath, string $destination, string $prefix = ''): void
    {
        $zip = new ZipArchive;

        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new RuntimeException("Could not open archive [{$zipPath}] (code {$opened}).");
        }

        try {
            self::assertSafeEntries($zip);

            $normalizedPrefix = $prefix === ''
                ? ''
                : rtrim(str_replace('\\', '/', $prefix), '/').'/';

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));

                if (str_ends_with($name, '/')) {
                    continue;
                }

                if ($normalizedPrefix !== '' && ! str_starts_with($name, $normalizedPrefix)) {
                    continue;
                }

                $relative = substr($name, strlen($normalizedPrefix));

                if ($relative === '') {
                    continue;
                }

                $target = rtrim($destination, DIRECTORY_SEPARATOR.'/')
                    .DIRECTORY_SEPARATOR
                    .str_replace('/', DIRECTORY_SEPARATOR, $relative);

                $directory = dirname($target);

                if (! is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                $stream = $zip->getStream($name);

                if ($stream === false) {
                    throw new RuntimeException("Could not read [{$name}] from the archive.");
                }

                file_put_contents($target, $stream);

                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Recursively copy one directory tree to a new location.
     */
    public static function copyDirectory(string $from, string $to): void
    {
        mkdir($to, 0755, true);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $target = $to.DIRECTORY_SEPARATOR.$iterator->getSubPathName();

            if ($item->isDir()) {
                mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    /**
     * Recursively delete a directory, tolerating transient Windows locks
     * by suppressing per-item failures.
     */
    public static function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }

    /**
     * Reject archives containing absolute paths or traversal attempts
     * (zip slip) before anything touches the filesystem.
     */
    private static function assertSafeEntries(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            $normalized = str_replace('\\', '/', $name);

            if (
                str_contains($normalized, '../')
                || str_starts_with($normalized, '/')
                || preg_match('#^[A-Za-z]:#', $normalized) === 1
            ) {
                throw new RuntimeException("Archive contains an unsafe entry [{$name}].");
            }
        }
    }

    /**
     * Find the extracted directory that actually contains the plugin
     * entry file: the extraction root itself (flat layout) or the single
     * top-level directory holding it.
     *
     * @throws RuntimeException When no recognizable plugin is present.
     */
    private static function locatePluginRoot(string $destination): string
    {
        if (self::hasEntryFile($destination)) {
            return $destination;
        }

        foreach (scandir($destination) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }

            $candidate = $destination.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($candidate) && self::hasEntryFile($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Archive does not contain a recognizable plugin.');
    }

    /**
     * Whether the directory contains a "*-plugin.php" entry file.
     */
    private static function hasEntryFile(string $directory): bool
    {
        return (bool) (glob($directory.'/*-plugin.php'));
    }
}
