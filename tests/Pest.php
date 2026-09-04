<?php

/*
|--------------------------------------------------------------------------
| Shared test helpers for the plugins package suite
|--------------------------------------------------------------------------
|
| Pest automatically loads this file. Filesystem helpers live here once
| so every test file in the suite can use them without redeclaring
| globals (which would fatal when files share one process).
|
*/

if (! function_exists('copyDirectoryTree')) {
    /**
     * Recursively copy a directory tree to a new location.
     */
    function copyDirectoryTree(string $from, string $to): void
    {
        mkdir($to, 0755, true);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $destination = $to.DIRECTORY_SEPARATOR.$iterator->getSubPathName();

            if ($item->isDir()) {
                mkdir($destination, 0755, true);
            } else {
                copy($item->getPathname(), $destination);
            }
        }
    }
}

if (! function_exists('deleteDirectoryTree')) {
    /**
     * Recursively delete a directory tree. Tolerates transient Windows
     * handle locks with a retry + gc pass; never throws if a stubborn
     * entry survives (leftover temp dirs are harmless).
     */
    function deleteDirectoryTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir()
                    ? @rmdir($item->getPathname())
                    : @unlink($item->getPathname());
            }

            if (@rmdir($directory)) {
                return;
            }

            gc_collect_cycles();
            usleep(200_000);
        }
    }
}

if (! function_exists('writePluginFixture')) {
    /**
     * Write a minimal plugin fixture (header + main class) into a
     * staging directory.
     *
     * @param  string  $stagingRoot  Directory that will contain "vendor/name".
     * @param  string  $vendor  Plugin vendor segment.
     * @param  string  $name  Plugin name segment.
     * @param  string  $version  Version written into the header.
     * @param  string|null  $namespace  Optional Namespace header value.
     * @return string Path of the created plugin directory.
     */
    function writePluginFixture(
        string $stagingRoot,
        string $vendor,
        string $name,
        string $version = '1.0.0',
        ?string $namespace = null,
    ): string {
        $pluginPath = $stagingRoot.DIRECTORY_SEPARATOR.$vendor.DIRECTORY_SEPARATOR.$name;

        mkdir($pluginPath.DIRECTORY_SEPARATOR.'src', 0755, true);

        $studly = str_replace('-', '', ucwords($name, '-'));
        $kebab = strtolower(str_replace('_', '-', $name));
        $namespaceValue = $namespace ?? 'Salienture\\'.$studly;
        $namespaceParts = explode('\\', $namespaceValue);
        $classNamespace = implode('\\', $namespaceParts);
        $class = $classNamespace.'\\'.$studly.'Plugin';

        file_put_contents(
            $pluginPath.DIRECTORY_SEPARATOR.strtolower($name).'-plugin.php',
            <<<PHP
            <?php

            /*
             * Plugin Name: {$studly}
             * Description: Fixture plugin for tests.
             * Version: {$version}
             * Plugin URI: https://salienture.com/plugins/{$kebab}
             * Author: Salienture
             * Author URI: https://salienture.com
             * License: MIT
             * Text Domain: salienture-{$kebab}
             * Requires PHP: 8.3
             * Requires Laravel: 13.0
             * Update URI: https://marketplace.salienture.com/api/plugins/{$vendor}/{$kebab}.json
             * Namespace: {$classNamespace}
             * Plugin Class: {$class}
             */

            require_once __DIR__.'/src/{$studly}Plugin.php';

            PHP,
        );

        // The class file path expected by the scanner's structure check:
        // PSR-4 relative to the declared Namespace, under src/.
        $relativeClassPath = str_replace(
            '\\',
            '/',
            substr($class, strlen($classNamespace) + 1),
        ).'.php';

        $classFile = $pluginPath.DIRECTORY_SEPARATOR.'src'
            .DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeClassPath);

        $directory = dirname($classFile);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($classFile, "<?php\n\nclass Dummy {}\n");

        return $pluginPath;
    }
}

if (! function_exists('zipDirectory')) {
    /**
     * Zip a directory tree into the given archive path.
     *
     * @param  array<string, string>|null  $extraFiles  Map of absolute file
     *                                                  => archive-relative path added on top.
     */
    function zipDirectory(string $sourceDir, string $zipPath, ?array $extraFiles = null): void
    {
        $zip = new ZipArchive;

        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            $relative = ltrim(str_replace('\\', '/', substr((string) $file->getPathname(), strlen(rtrim(str_replace('\\', '/', $sourceDir), '/')))), '/');

            $zip->addFile((string) $file->getPathname(), $relative);
        }

        foreach ($extraFiles ?? [] as $absolute => $archiveRelative) {
            $zip->addFile($absolute, $archiveRelative);
        }

        $zip->close();
    }
}
