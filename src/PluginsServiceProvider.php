<?php

namespace Salienture\Plugins;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Salienture\Plugins\Console\CheckPluginsUpdatesCommand;
use Salienture\Plugins\Console\ListPluginsCommand;
use Salienture\Plugins\Console\UpdatePluginsCommand;
use Salienture\Plugins\Support\PluginAutoloader;
use Salienture\Plugins\Support\PluginManager;
use Salienture\Plugins\Support\PluginRepository;
use Salienture\Plugins\Support\Updater;
use Throwable;

/**
 * Salienture plugin system service provider.
 *
 * Registers the package configuration, migrations, admin routes and console
 * commands, owns the update schedule, and boots every active plugin after
 * the application is ready. Official Salienture packages register through
 * composer; drop-in plugins need no registration at all.
 */
class PluginsServiceProvider extends ServiceProvider
{
    /**
     * Bind the plugin singletons into the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/plugins.php', 'plugins');

        $this->app->singleton(PluginAutoloader::class);
        $this->app->singleton(PluginRepository::class, fn (): PluginRepository => new PluginRepository(
            (string) config('plugins.paths.plugins'),
        ));

        $this->app->singleton(PluginManager::class, fn ($app): PluginManager => new PluginManager(
            $app->make(PluginRepository::class),
            $app->make(PluginAutoloader::class),
            $app,
            rtrim($app->basePath(), '\\/'),
        ));

        $this->app->singleton(Updater::class, fn ($app): Updater => new Updater(
            $app->make(PluginRepository::class),
            $app->make(PluginManager::class),
        ));
    }

    /**
     * Boot the package: publishables, commands, gate and plugin bootstrapping.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/plugins.php' => config_path('plugins.php'),
            ], 'salienture-plugins-config');

            $this->publishes([
                __DIR__.'/../resources/js/Pages' => resource_path('js/Pages'),
            ], 'salienture-plugins-pages');

            $this->commands([
                ListPluginsCommand::class,
                CheckPluginsUpdatesCommand::class,
                UpdatePluginsCommand::class,
            ]);

            $this->scheduleUpdates();
        }

        Gate::define((string) config('plugins.gate'), fn ($user): bool => true);

        $this->app->booted(function (): void {
            if (! Schema::hasTable('plugins')) {
                return;
            }

            try {
                $this->app->make(PluginManager::class)->registerActive();
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * Update checks and auto-updates are owned by this package; the host
     * app does not need any scheduler entries.
     */
    /**
     * Register the daily update check and auto-update commands on the
     * scheduler unless globally disabled via config/env.
     */
    private function scheduleUpdates(): void
    {
        if (! (bool) config('plugins.auto_update.enabled', true)) {
            return;
        }

        Schedule::command('salienture:plugins:check-updates')->dailyAt('03:00');
        Schedule::command('salienture:plugins:update')->dailyAt('03:30');
    }
}
