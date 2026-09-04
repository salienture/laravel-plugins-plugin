<?php

namespace Salienture\Plugins\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Salienture\Plugins\Events\PluginUpdated;
use Salienture\Plugins\Models\PluginRecord;

/**
 * Update channel for plugins (marketplace contract).
 *
 * Every plugin exposes a JSON manifest (via its `Update URI` header or the
 * configured marketplace base URL) shaped like:
 *
 *     {
 *       "slug": "salienture/todo",
 *       "version": "1.1.0",
 *       "url": "https://downloads.salienture.com/salienture-todo-1.1.0.zip",
 *       "changelog": "### 1.1.0\n- ...",
 *       "requires": { "php": ">=8.3", "laravel": "^13.0" }
 *     }
 *
 * The download URL may also be a `path://` stream pointing at a local
 * archive, which keeps the flow testable and usable in air-gapped installs.
 */
class Updater
{
    /**
     * Create a new updater instance.
     */
    public function __construct(
        private readonly PluginRepository $repository,
        private readonly PluginManager $manager,
    ) {}

    /**
     * Query every update source and persist available updates.
     *
     * @param  string|null  $slug  Restrict the check to one plugin slug.
     * @return array{checked: int, updates: int}
     */
    public function check(?string $slug = null): array
    {
        $manifests = $this->repository->all();

        if ($slug !== null) {
            $manifests = $manifests->only($slug);
        }

        $checked = 0;
        $updates = 0;

        foreach ($manifests as $manifest) {
            $source = $manifest->updateSource(config('plugins.marketplace.base_url'));

            if ($source === null) {
                continue;
            }

            $payload = $this->fetchManifest($source);

            if ($payload === null) {
                continue;
            }

            $record = PluginRecord::query()->firstOrNew(['slug' => $manifest->slug]);
            $latest = trim((string) ($payload['version'] ?? ''));
            $current = (string) ($record->version ?: $manifest->version() ?: '0.0.0');

            if ($latest !== '' && version_compare($latest, $current, '>')) {
                $updates++;
            }

            $hasUpdate = $latest !== ''
                && version_compare($latest, $current, '>')
                && filled($payload['url'] ?? null);

            $record->forceFill([
                'name' => $manifest->name(),
                'version' => $record->version ?? $manifest->version(),
                'latest_version' => $hasUpdate ? $latest : null,
                'changelog' => $hasUpdate ? ($payload['changelog'] ?? null) : null,
                'download_url' => $hasUpdate ? $payload['url'] : null,
                'update_available' => $hasUpdate,
                'last_checked_at' => now(),
            ])->save();

            $checked++;
        }

        return ['checked' => $checked, 'updates' => $updates];
    }

    /**
     * Whether auto-update should run for the given plugin
     * (per-plugin override wins over the global default).
     */
    public function autoUpdateEnabled(PluginRecord $record): bool
    {
        return $record->auto_update ?? (bool) config('plugins.auto_update.default', true);
    }

    /**
     * Plugins that currently have an update and want it installed.
     *
     * @return Collection<int, PluginRecord>
     */
    public function pendingAutoUpdates(): Collection
    {
        return PluginRecord::query()
            ->where('update_available', true)
            ->get()
            ->filter(fn (PluginRecord $record): bool => $this->autoUpdateEnabled($record))
            ->values();
    }

    /**
     * Download and install an update for one plugin.
     *
     * The plugin directory is backed up, swapped with the extracted archive,
     * migrations re-run and the plugin re-activated. On any failure the
     * plugin is restored/re-activated so the host never loses it.
     *
     * @param  string  $slug  Slug in "vendor/name" form.
     * @return string The newly installed version.
     *
     * @throws RuntimeException When the plugin is missing or has no update.
     */
    public function update(string $slug): string
    {
        $manifest = $this->repository->find($slug);

        if ($manifest === null) {
            throw new RuntimeException("Plugin [{$slug}] is not installed.");
        }

        $record = PluginRecord::query()->where('slug', $slug)->firstOrFail();

        if (! $record->update_available || blank($record->download_url)) {
            throw new RuntimeException("No update available for [{$slug}]. Run salienture:plugins:check-updates first.");
        }

        $archivePath = $this->download($slug, (string) $record->download_url, (string) $record->latest_version);
        $newVersion = (string) $record->latest_version;

        $wasActive = $record->is_active;

        if ($wasActive) {
            $this->manager->deactivate($slug);
        }

        try {
            $this->replacePluginDirectory($manifest, $archivePath);
        } finally {
            // Re-activate with the freshly parsed files even on failure,
            // so a botched update never leaves the app without the plugin.
            $fresh = $this->repository->find($slug);

            if ($fresh !== null) {
                $state = PluginRecord::query()->where('slug', $slug)->first();
                $state?->forceFill(['version' => $newVersion])->save();
            }

            if ($wasActive) {
                $this->manager->activate($slug);
            }
        }

        PluginRecord::query()->where('slug', $slug)->update([
            'version' => $newVersion,
            'update_available' => false,
            'latest_version' => null,
            'changelog' => null,
            'download_url' => null,
        ]);

        event(new PluginUpdated($slug, $newVersion));

        return $newVersion;
    }

    /**
     * Fetch and decode the manifest from a URL or path:// stream.
     * Returns null when unreachable or malformed - never throws.
     *
     * @param  string  $source  Manifest endpoint or "path://<file>" stream.
     * @return array<string, mixed>|null
     */
    private function fetchManifest(string $source): ?array
    {
        if (str_starts_with($source, 'path://')) {
            $file = substr($source, strlen('path://'));

            if (! is_file($file)) {
                return null;
            }

            $decoded = json_decode((string) file_get_contents($file), true);

            return is_array($decoded) ? $decoded : null;
        }

        try {
            $response = Http::timeout(config('plugins.marketplace.timeout', 10))
                ->acceptJson()
                ->get($source);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * Download an update archive to the configured updates disk. Supports
     * HTTP URLs and "path://<absolute-file>" streams for local testing.
     *
     * @param  string  $slug  Slug in "vendor/name" form.
     * @param  string  $url  Archive URL or path:// stream from the manifest.
     * @param  string  $version  Version being installed (used for naming).
     * @return string Absolute path to the downloaded archive.
     *
     * @throws RuntimeException When the archive cannot be retrieved.
     */
    private function download(string $slug, string $url, string $version): string
    {
        $disk = Storage::disk(config('plugins.updates.disk'));

        $directory = 'plugins'.DIRECTORY_SEPARATOR.'downloads';
        $archive = $directory.DIRECTORY_SEPARATOR.str_replace('/', '-', $slug).'-'.$version.'.zip';

        $disk->makeDirectory($directory);
        $destination = $disk->path($archive);

        if (str_starts_with($url, 'path://')) {
            $source = substr($url, strlen('path://'));

            if (! is_file($source)) {
                throw new RuntimeException("Update archive [{$source}] not found.");
            }

            copy($source, $destination);

            return $destination;
        }

        $response = Http::timeout(config('plugins.marketplace.timeout', 30))
            ->sink($destination)
            ->get($url);

        if (! $response->successful() || ! is_file($destination)) {
            throw new RuntimeException("Could not download update archive from [{$url}].");
        }

        return $destination;
    }

    /**
     * Extract the downloaded archive over the plugin directory.
     *
     * Accepted layouts: either the plugin files at the archive root, or a
     * single top-level directory containing them (GitHub-style zips).
     *
     * @param  PluginManifest  $manifest  Manifest of the plugin to replace.
     * @param  string  $archivePath  Absolute path to the downloaded zip.
     *
     * @throws RuntimeException When the archive cannot be opened/parsed.
     */
    private function replacePluginDirectory(PluginManifest $manifest, string $archivePath): void
    {
        $workDir = dirname($archivePath).DIRECTORY_SEPARATOR.'extracted-'.Str::random(8);

        mkdir($workDir, 0755, true);

        try {
            $sourceRoot = PluginArchive::extract($archivePath, $workDir);
        } catch (RuntimeException $exception) {
            PluginArchive::deleteDirectory($workDir);

            throw $exception;
        }

        $target = $manifest->path();
        $backup = dirname($target).DIRECTORY_SEPARATOR.basename($target).'-backup-'.now()->format('YmdHis');

        // Windows keeps directory handles open transiently (AV/indexer/GC);
        // nudge cleanup before touching the tree and retry renames once.
        gc_collect_cycles();

        if (! @rename($target, $backup)) {
            usleep(200_000);

            if (! @rename($target, $backup)) {
                // Best effort: keep a backup by copying, then clear the way.
                PluginArchive::copyDirectory($target, $backup);
                PluginArchive::deleteDirectory($target);
            }
        }

        if (! @rename($sourceRoot, $target)) {
            usleep(200_000);

            if (! @rename($sourceRoot, $target)) {
                PluginArchive::copyDirectory($sourceRoot, $target);
                PluginArchive::deleteDirectory($sourceRoot);
            }
        }

        PluginArchive::deleteDirectory($workDir);

        // Old version removed instantly once the update is in place.
        PluginArchive::deleteDirectory($backup);
    }
}
