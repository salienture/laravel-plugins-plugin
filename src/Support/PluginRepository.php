<?php

namespace Salienture\Plugins\Support;

use Illuminate\Support\Collection;

/**
 * Discovers plugins on disk by scanning the configured plugin paths.
 *
 * Layout (WordPress-style, two levels deep):
 *
 *     plugins/
 *       salienture/
 *         todo/
 *           todo-plugin.php   <- entry file carrying the plugin header
 */
class PluginRepository
{
    /**
     * Create a new repository scanning the given discovery root.
     */
    public function __construct(
        private readonly string $pluginsPath,
    ) {}

    /**
     * Discover every plugin under the configured path.
     *
     * @return Collection<string, PluginManifest> Keyed by slug.
     */
    public function all(): Collection
    {
        $plugins = collect();

        foreach ($this->entryFiles() as $slug => $entryFile) {
            $manifest = PluginManifest::parse($slug, $entryFile);

            if (isset($manifest->headers['name'])) {
                $plugins->put($slug, $manifest);
            }
        }

        return $plugins->sortKeys();
    }

    /**
     * Find a single discovered plugin by its slug ("vendor/name").
     */
    public function find(string $slug): ?PluginManifest
    {
        return $this->all()->get($slug);
    }

    /**
     * Collect the entry files of all candidate plugin directories.
     *
     * @return array<string, string> slug => entry file path
     */
    private function entryFiles(): array
    {
        if (! is_dir($this->pluginsPath)) {
            return [];
        }

        $files = [];

        foreach (scandir($this->pluginsPath) ?: [] as $vendor) {
            if (str_starts_with($vendor, '.')) {
                continue;
            }

            $vendorPath = $this->pluginsPath.DIRECTORY_SEPARATOR.$vendor;

            foreach (scandir($vendorPath) ?: [] as $name) {
                if (str_starts_with($name, '.')) {
                    continue;
                }

                $pluginPath = $vendorPath.DIRECTORY_SEPARATOR.$name;

                if (! is_dir($pluginPath)) {
                    continue;
                }

                $entryFile = $this->resolveEntryFile($pluginPath, $name);

                if ($entryFile !== null) {
                    $files[$vendor.'/'.$name] = $entryFile;
                }
            }
        }

        return $files;
    }

    /**
     * Resolve the header-carrying entry file of one plugin directory:
     * "<name>-plugin.php" following the naming convention, otherwise the
     * first "*-plugin.php" file found.
     *
     * @param  string  $pluginPath  Absolute plugin directory.
     * @param  string  $name  Plugin directory basename.
     * @return string|null Absolute entry file path, or null when absent.
     */
    private function resolveEntryFile(string $pluginPath, string $name): ?string
    {
        $conventional = $pluginPath.DIRECTORY_SEPARATOR.strtolower(str_replace('_', '-', $name)).'-plugin.php';

        if (file_exists($conventional)) {
            return $conventional;
        }

        // Fallback: first PHP file whose header declares a plugin name.
        foreach (glob($pluginPath.'/*-plugin.php') ?: [] as $candidate) {
            return $candidate;
        }

        return null;
    }
}
