<?php

namespace Salienture\Plugins\Console;

use Illuminate\Console\Command;
use Salienture\Plugins\Support\PluginManager;

/**
 * Console command: "php artisan salienture:plugins:list".
 *
 * Prints a table of every discovered plugin with version, activation,
 * auto-update preference and pending update information.
 */
class ListPluginsCommand extends Command
{
    protected $signature = 'salienture:plugins:list';

    protected $description = 'List all discovered plugins and their state';

    /**
     * Render a table of every discovered plugin and its lifecycle state.
     */
    public function handle(PluginManager $manager): int
    {
        $plugins = $manager->all();

        if ($plugins->isEmpty()) {
            $this->components->info('No plugins discovered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Name', 'Version', 'Active', 'Auto-update', 'Update available'],
            $plugins->map(fn (array $plugin): array => [
                $plugin['slug'],
                $plugin['name'],
                $plugin['version'] ?? '-',
                $plugin['isActive'] ? 'yes' : 'no',
                match ($plugin['autoUpdate']) {
                    true => 'yes',
                    false => 'no',
                    default => 'global',
                },
                $plugin['updateAvailable']
                    ? sprintf('%s -> %s', $plugin['version'], $plugin['latestVersion'])
                    : '-',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
