<?php

use Illuminate\Support\Facades\Route;
use Salienture\Plugins\Http\Controllers\PluginAdminController;

/**
 * Plugin management area: management page plus activate/deactivate,
 * auto-update preference, update check and update install endpoints.
 * Every route requires auth, verification and the "managePlugins" ability.
 */
Route::middleware(['web', 'auth', 'verified'])
    ->prefix('plugins')
    ->name('plugins.')
    ->group(function (): void {
        Route::get('/', [PluginAdminController::class, 'index'])->name('index');

        Route::post('check-updates', [PluginAdminController::class, 'checkUpdates'])->name('check-updates');

        /**
         * Upload + install a plugin zip; the archive is deleted right
         * after extraction (success or failure).
         */
        Route::post('upload', [PluginAdminController::class, 'upload'])->name('upload');

        /**
         * Trash lifecycle: move an inactive plugin to trash, restore it,
         * delete one trashed plugin permanently (dropping its tables) or
         * empty the whole trash.
         */
        Route::post('trash/empty', [PluginAdminController::class, 'emptyTrash'])
            ->name('trash.empty');
        Route::post('trash/{folder}/restore', [PluginAdminController::class, 'restore'])
            ->where('folder', '[A-Za-z0-9._\-]+')
            ->name('trash.restore');
        Route::delete('trash/{folder}', [PluginAdminController::class, 'destroyPermanently'])
            ->where('folder', '[A-Za-z0-9._\-]+')
            ->name('trash.destroy');

        Route::post('{slug}/toggle', [PluginAdminController::class, 'toggle'])
            ->where('slug', '[A-Za-z0-9._\-]+(/[A-Za-z0-9._\-]+)?')
            ->name('toggle');
        Route::post('{slug}/auto-update', [PluginAdminController::class, 'toggleAutoUpdate'])
            ->where('slug', '[A-Za-z0-9._\-]+(/[A-Za-z0-9._\-]+)?')
            ->name('auto-update');
        Route::post('{slug}/update', [PluginAdminController::class, 'update'])
            ->where('slug', '[A-Za-z0-9._\-]+(/[A-Za-z0-9._\-]+)?')
            ->name('update');
        Route::post('{slug}/trash', [PluginAdminController::class, 'trash'])
            ->where('slug', '[A-Za-z0-9._\-]+(/[A-Za-z0-9._\-]+)?')
            ->name('trash');
    });
