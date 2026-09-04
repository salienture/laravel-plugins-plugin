<?php

namespace Salienture\Plugins\Support;

/**
 * Minimal runtime PSR-4 autoloader for plugin classes, so plugins stay
 * drop-in folders that do not require composer dumps on install.
 */
final class PluginAutoloader
{
    /**
     * @var array<string, string> namespace prefix => source directory
     */
    private array $prefixes = [];

    private bool $registered = false;

    /**
     * Register the autoloader with SPL (idempotent).
     */
    public function register(): void
    {
        if (! $this->registered) {
            spl_autoload_register([$this, 'loadClass']);
            $this->registered = true;
        }
    }

    /**
     * Map a namespace prefix to a source directory and ensure the
     * autoloader is registered.
     *
     * @param  string  $prefix  Namespace prefix, e.g. "Salienture\Todo".
     * @param  string  $directory  Absolute PSR-4 base directory.
     */
    public function addNamespace(string $prefix, string $directory): void
    {
        $this->prefixes[rtrim($prefix, '\\').'\\'] = rtrim($directory, '/\\').DIRECTORY_SEPARATOR;
        $this->register();
    }

    /**
     * Attempt to require the class file for the given class name.
     * Silently does nothing when no registered prefix matches.
     */
    public function loadClass(string $class): void
    {
        foreach ($this->prefixes as $prefix => $baseDir) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
            $file = $baseDir.$relative.'.php';

            if (is_file($file)) {
                require_once $file;

                return;
            }
        }
    }
}
