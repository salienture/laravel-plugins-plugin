<?php

namespace Salienture\Plugins\Support;

/**
 * WordPress-style plugin header, parsed from the entry PHP file docblock.
 *
 * Example header:
 *
 *     /*
 *      * Plugin Name: Todo
 *      * Description: Personal todo lists.
 *      * Version: 1.0.0
 *      * Plugin URI: https://salienture.com/plugins/todo
 *      * Author: Salienture
 *      * Author URI: https://salienture.com
 *      * License: MIT
 *      * Text Domain: salienture-todo
 *      * Requires PHP: 8.3
 *      * Requires Laravel: 13.0
 *      * Update URI: https://market.salienture.com/plugins/salienture/todo/manifest.json
 *      * Namespace: Salienture\Todo
 *      * Plugin Class: Salienture\Todo\TodoPlugin
 *      *\/
 */
final class PluginManifest
{
    /**
     * Header keys mapped to normalized manifest keys.
     *
     * @var array<string, string>
     */
    private const HEADER_KEYS = [
        'plugin name' => 'name',
        'description' => 'description',
        'version' => 'version',
        'plugin uri' => 'pluginUri',
        'author' => 'author',
        'author uri' => 'authorUri',
        'license' => 'license',
        'text domain' => 'textDomain',
        'requires php' => 'requiresPhp',
        'requires laravel' => 'requiresLaravel',
        'update uri' => 'updateUri',
        'namespace' => 'namespace',
        'plugin class' => 'pluginClass',
    ];

    /**
     * Create a new manifest instance.
     *
     * @param  string  $slug  Plugin slug in "vendor/name" form.
     * @param  string  $entryFile  Absolute path to the entry PHP file.
     * @param  array<string, string>  $headers  Normalized headers.
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $entryFile,
        public readonly array $headers,
    ) {}

    /**
     * Parse the header of a plugin entry file into a manifest.
     *
     * @param  string  $slug  Plugin slug in "vendor/name" form.
     * @param  string  $entryFile  Absolute path to the entry PHP file.
     */
    public static function parse(string $slug, string $entryFile): self
    {
        $contents = (string) file_get_contents($entryFile, false, null, 0, 8192);

        return self::fromContents($slug, $contents, $entryFile);
    }

    /**
     * Parse a header from raw file contents. Used when the entry file is
     * not on disk yet, e.g. streamed straight out of an uploaded archive.
     *
     * @param  string  $slug  Slug (or placeholder) for this manifest.
     * @param  string  $contents  Raw contents of the entry file.
     * @param  string  $entryFile  Path (or virtual path) for reference.
     */
    public static function fromContents(string $slug, string $contents, string $entryFile): self
    {
        preg_match_all('/^(?:[ \t]*(?:\*|#|\/\/)[ \t]*)?([A-Z][A-Za-z ]+):\s*(.+)$/m', $contents, $matches);

        $headers = [];

        foreach ($matches[1] as $index => $rawKey) {
            $key = strtolower(trim($rawKey));

            if (! isset(self::HEADER_KEYS[$key])) {
                continue;
            }

            $headers[self::HEADER_KEYS[$key]] = trim($matches[2][$index]);
        }

        return new self($slug, $entryFile, $headers);
    }

    /**
     * The plugin display name, falling back to its slug.
     */
    public function name(): string
    {
        return $this->headers['name'] ?? $this->slug;
    }

    /**
     * The declared plugin version, e.g. "1.0.0".
     */
    public function version(): ?string
    {
        return $this->headers['version'] ?? null;
    }

    /**
     * The short human-readable description from the header.
     */
    public function description(): ?string
    {
        return $this->headers['description'] ?? null;
    }

    /**
     * The author name from the header.
     */
    public function author(): ?string
    {
        return $this->headers['author'] ?? null;
    }

    /**
     * Resolve the fully qualified plugin main class name:
     * the explicit "Plugin Class" header when present, otherwise
     * "{Namespace}\{StudlyName}Plugin".
     */
    public function pluginClass(): ?string
    {
        $class = $this->headers['pluginClass'] ?? null;

        if ($class !== null) {
            return '\\'.ltrim($class, '\\');
        }

        $namespace = $this->headers['namespace'] ?? null;

        if ($namespace === null) {
            return null;
        }

        $studly = str($this->slug)->afterLast('/')->studly()->toString();

        return '\\'.$namespace.'\\'.$studly.'Plugin';
    }

    /**
     * Absolute path to the plugin root directory.
     */
    public function path(): string
    {
        return dirname($this->entryFile);
    }

    /**
     * Directory containing the PSR-4 classes of this plugin.
     */
    public function sourcePath(): ?string
    {
        if (! isset($this->headers['namespace'])) {
            return null;
        }

        return $this->path().DIRECTORY_SEPARATOR.'src';
    }

    /**
     * Directory containing the frontend pages of this plugin for the given
     * host frontend type (react | vue | livewire), per the unified plugin
     * layout convention:
     *
     *   React:   resources/js/react/pages
     *   Vue:     resources/js/vue/pages
     *   Livewire: resources/views/livewire
     *
     * @param  string  $frontend  Host frontend type: react|vue|livewire.
     * @return string|null Absolute directory when it exists, null otherwise.
     */
    public function pagesPath(string $frontend): ?string
    {
        $relative = match ($frontend) {
            'react' => 'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'react'.DIRECTORY_SEPARATOR.'pages',
            'vue' => 'resources'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'vue'.DIRECTORY_SEPARATOR.'pages',
            'livewire' => 'resources'.DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR.'livewire',
            default => null,
        };

        if ($relative === null) {
            return null;
        }

        $path = $this->path().DIRECTORY_SEPARATOR.$relative;

        return is_dir($path) ? $path : null;
    }

    /**
     * Resolve the update manifest endpoint for this plugin:
     * - absolute URL or path:// stream from the `Update URI` header,
     * - otherwise the configured marketplace endpoint by slug.
     *
     * @param  string|null  $marketplaceBase  Configured marketplace base URL.
     */
    public function updateSource(?string $marketplaceBase): ?string
    {
        $uri = $this->headers['updateUri'] ?? null;

        if ($uri !== null && $uri !== '') {
            return str_starts_with($uri, 'path://') ? $uri : rtrim($uri, '/');
        }

        if ($marketplaceBase === null || $marketplaceBase === '') {
            return null;
        }

        return rtrim($marketplaceBase, '/').'/'.str_replace('\\', '/', $this->slug).'.json';
    }
}
