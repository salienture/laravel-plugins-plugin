<?php

namespace Salienture\Plugins\Console;

use Illuminate\Console\Command;
use Salienture\Plugins\Support\PluginManager;
use Salienture\Plugins\Support\Updater;

/**
 * Console command: "php artisan salienture:plugins:check-updates".
 *
 * Queries each plugin's update source, persists newer versions found and
 * prints a summary. Optionally restricted to a single slug.
 */
class CheckPluginsUpdatesCommand extends Command
{
    protected $signature = 'salienture:plugins:check-updates {slug? : Only check this plugin}';

    protected $description = 'Check configured update sources for newer plugin versions';

    /**
     * Query update sources and report available newer versions.
     */
    public function handle(PluginManager $manager, Updater $updater): int
    {
        $slug = $this->argument('slug');

        if (is_string($slug) && $slug !== '' && $manager->find($slug) === null) {
            $this->components->error("Plugin [{$slug}] is not installed.");

            return self::FAILURE;
        }

        $result = $updater->check(is_string($slug) && $slug !== '' ? $slug : null);

        $this->components->info(sprintf(
            '%d update source(s) checked, %d update(s) available.',
            $result['checked'],
            $result['updates'],
        ));

        foreach ($manager->all() as $plugin) {
            if ($plugin['updateAvailable']) {
                $this->components->warn(sprintf(
                    '%s %s -> %s',
                    $plugin['slug'],
                    $plugin['version'],
                    $plugin['latestVersion'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
