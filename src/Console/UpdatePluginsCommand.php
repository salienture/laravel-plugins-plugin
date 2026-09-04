<?php

namespace Salienture\Plugins\Console;

use Illuminate\Console\Command;
use Salienture\Plugins\Models\PluginRecord;
use Salienture\Plugins\Support\Updater;
use Throwable;

/**
 * Console command: "php artisan salienture:plugins:update".
 *
 * Installs pending updates for one plugin or, when no slug is given, for
 * every plugin with auto-update enabled. Use --force to bypass a
 * per-plugin auto-update opt-out.
 */
class UpdatePluginsCommand extends Command
{
    protected $signature = 'salienture:plugins:update
        {slug? : Update only this plugin}
        {--force : Update even if auto-update is disabled for the plugin}';

    protected $description = 'Install available plugin updates (auto-updates enabled plugins by default)';

    /**
     * Install pending updates for one slug or all auto-update plugins.
     */
    public function handle(Updater $updater): int
    {
        $slug = $this->argument('slug');

        $targets = collect();

        if (is_string($slug) && $slug !== '') {
            $record = PluginRecord::query()->where('slug', $slug)->first();

            if ($record === null) {
                $this->components->error("Plugin [{$slug}] has not been activated yet; nothing to update.");

                return self::FAILURE;
            }

            if (! $updater->autoUpdateEnabled($record) && ! $this->option('force')) {
                $this->components->warn("Auto-update is disabled for [{$slug}]; use --force to update anyway.");

                return self::FAILURE;
            }

            $targets->push($slug);
        } else {
            $targets = $updater->pendingAutoUpdates()->map(
                fn (PluginRecord $record): string => $record->slug,
            )->values();
        }

        foreach ($targets as $target) {
            try {
                $version = $updater->update($target);

                $this->components->info("Updated [{$target}] to {$version}.");
            } catch (Throwable $exception) {
                $this->components->error("Failed updating [{$target}]: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
