<?php

namespace Salienture\Plugins\Support;

use Illuminate\Support\Str;

/**
 * Result of a successful archive inspection.
 */
class Inspection
{
    /**
     * Create a new inspection result.
     *
     * @param  string  $prefix  Common archive prefix of the plugin
     *                          ("vendor/name/", a single folder, or "" flat).
     * @param  PluginManifest  $manifest  Parsed header manifest.
     */
    public function __construct(
        public readonly string $prefix,
        public readonly PluginManifest $manifest,
    ) {}

    /**
     * The resolved slug segments for this archive.
     *
     * Resolution order:
     * - two-level prefix ("salienture/todo/") -> used directly;
     * - deeper prefix (e.g. GitHub-style "main/salienture/todo/")
     *   -> last two segments are used;
     * - single folder ("todo/") or flat archive -> derived from the
     *   Namespace header ("Salienture\Todo" -> salienture/todo).
     *
     * @return array{0: string, 1: string} Vendor and name segments.
     *
     * @throws PluginRejected When no valid vendor/name can be determined.
     */
    public function slugParts(): array
    {
        if ($this->prefix !== '') {
            $segments = explode('/', rtrim($this->prefix, '/'));

            if (count($segments) >= 2) {
                [$vendor, $name] = array_slice($segments, -2, 2);

                return [strtolower($vendor), strtolower(Str::kebab(str_replace('-', ' ', $name)))];
            }

            return $this->slugFromNamespace();
        }

        return $this->slugFromNamespace();
    }

    /**
     * Derive "vendor/name" from the Namespace header
     * ("Salienture\Todo" -> salienture/todo).
     *
     * @return array{0: string, 1: string}
     *
     * @throws PluginRejected When no usable Namespace exists.
     */
    private function slugFromNamespace(): array
    {
        $namespace = $this->manifest->headers['namespace'] ?? null;

        if (! is_string($namespace) || ! str_contains($namespace, '\\')) {
            throw new PluginRejected([
                'Could not determine the plugin vendor/name from the archive layout or Namespace header.',
            ]);
        }

        $parts = explode('\\', ltrim($namespace, '\\'));

        $vendor = Str::kebab(array_shift($parts));
        $name = Str::kebab((string) end($parts));

        return [strtolower((string) $vendor), strtolower((string) $name)];
    }
}
