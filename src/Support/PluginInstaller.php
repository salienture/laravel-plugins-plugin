<?php

namespace Salienture\Plugins\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Salienture\Plugins\Models\PluginRecord;

/**
 * Installs uploaded plugin archives into the discovery path.
 *
 * Flow: persist the upload -> SECURITY INSPECTION (streaming pass over
 * the archive, nothing is extracted before it passes) -> extract only the
 * validated plugin directory -> replace-or-create -> refresh state. The
 * uploaded archive and every temporary file are deleted afterwards, even
 * when installation fails.
 *
 * Activation semantics follow WordPress: a brand new plugin stays inactive
 * until an admin activates it; replacing a plugin that was active
 * re-activates it automatically.
 */
class PluginInstaller
{
    /**
     * Create a new installer instance.
     */
    public function __construct(
        private readonly PluginRepository $repository,
        private readonly PluginManager $manager,
    ) {}

    /**
     * Install (or replace) a plugin from an uploaded zip archive.
     *
     * @param  UploadedFile  $upload  The validated zip upload.
     * @return array{slug: string, replaced: bool}
     *
     * @throws RuntimeException When the file is not a readable zip.
     * @throws PluginRejected On any security or structure violation.
     */
    public function install(UploadedFile $upload): array
    {
        $disk = Storage::disk((string) config('plugins.updates.disk', 'local'));

        $directory = 'plugins'.DIRECTORY_SEPARATOR.'uploads';

        $disk->makeDirectory($directory);

        $stored = $disk->putFile($directory, $upload);
        $zipPath = $disk->path((string) $stored);

        try {
            // Security + structure gate: runs entirely against the zip,
            // before a single file is extracted to disk.
            $inspection = PluginUploadScanner::inspect($zipPath);

            [$vendor, $name] = $inspection->slugParts();

            $slug = strtolower($vendor).'/'.$name;

            $result = $this->placePlugin($slug, $zipPath, $inspection);

            $record = PluginRecord::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $inspection->manifest->name()],
            );

            $record->forceFill(['version' => $inspection->manifest->version()])->save();

            if ($result['wasActive']) {
                $this->manager->activate($slug);
            }

            return ['slug' => $slug, 'replaced' => $result['replaced']];
        } finally {
            // The upload is transient by design: always clean up.
            @unlink($zipPath);
        }
    }

    /**
     * Extract the validated archive over its final location. Existing
     * installations are backed up next to themselves first; activation
     * state is remembered for the caller to restore afterwards.
     *
     * @param  string  $slug  Target slug in "vendor/name" form.
     * @param  string  $zipPath  Stored archive path.
     * @param  Inspection  $inspection  Result of the passed security scan.
     * @return array{replaced: bool, wasActive: bool}
     */
    private function placePlugin(string $slug, string $zipPath, Inspection $inspection): array
    {
        $target = (string) config('plugins.paths.plugins')
            .DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $slug);

        $existing = PluginRecord::query()->where('slug', $slug)->first();
        $wasActive = (bool) ($existing?->is_active ?? false);

        if ($wasActive) {
            $this->manager->deactivate($slug);
        }

        $replaced = is_dir($target);
        $backup = null;

        if ($replaced) {
            gc_collect_cycles();

            $backup = dirname($target).DIRECTORY_SEPARATOR.basename($target).'-backup-'.now()->format('YmdHis');

            if (! @rename($target, $backup)) {
                usleep(200_000);

                if (! @rename($target, $backup)) {
                    PluginArchive::copyDirectory($target, $backup);
                    PluginArchive::deleteDirectory($target);
                }
            }
        }

        $parent = dirname($target);

        if (! is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        // The archive prefix ("vendor/name/") maps onto $target itself,
        // so entries land directly inside the plugin directory.
        PluginArchive::extractToDirectory($zipPath, $target, $inspection->prefix);

        // Old version removed instantly once the new one is in place.
        if (is_string($backup) && is_dir($backup)) {
            PluginArchive::deleteDirectory($backup);
        }

        $this->manager->flush();

        return ['replaced' => $replaced, 'wasActive' => $wasActive];
    }
}
