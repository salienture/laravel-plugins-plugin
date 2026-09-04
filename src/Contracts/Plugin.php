<?php

namespace Salienture\Plugins\Contracts;

use Illuminate\Contracts\Container\Container;

/**
 * Contract every Salienture plugin main class must implement.
 *
 * The fully qualified class name is declared in the plugin header
 * (`Plugin Class`) of the plugin entry file, WordPress-style.
 */
interface Plugin
{
    /**
     * Register bindings into the container. Called before boot().
     */
    public function register(Container $app): void;

    /**
     * Boot the plugin. Called for active plugins only, on every request.
     */
    public function boot(Container $app): void;

    /**
     * Called once, right after the plugin has been activated by an admin.
     * Run heavy setup here (seeding, cache warmup, ...).
     * Migrations are executed automatically by the core before this hook.
     */
    public function activate(): void;

    /**
     * Called once, right after the plugin has been deactivated.
     * Data/migrations are intentionally kept on disk and in the database.
     */
    public function deactivate(): void;

    /**
     * Menu entries contributed by this plugin.
     *
     * Consumed by menu integrations (e.g. the upcoming Salienture Menus
     * plugin); the core itself never injects them anywhere.
     *
     * @return array<int, array{title: string, href: string, icon?: string}>
     */
    public function navigation(): array;
}
