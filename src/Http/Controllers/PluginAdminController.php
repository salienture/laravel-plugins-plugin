<?php

namespace Salienture\Plugins\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Salienture\Plugins\Support\PluginInstaller;
use Salienture\Plugins\Support\PluginManager;
use Salienture\Plugins\Support\PluginRejected;
use Salienture\Plugins\Support\Updater;
use Throwable;

/**
 * Admin controller behind /admin/plugins: renders the management page and
 * handles activation, auto-update preference, update checks and installs.
 */
class PluginAdminController
{
    /**
     * Show the plugin management page.
     */
    public function index(PluginManager $manager): Response
    {
        return Inertia::render('admin/plugins', [
            'canManagePlugins' => Gate::allows((string) config('plugins.gate')),
            'plugins' => $manager->all(),
            'autoUpdateGloballyEnabled' => (bool) config('plugins.auto_update.enabled', true),
            'updatesPending' => $manager->all()->where('updateAvailable', true)->count(),
            'maxUploadKb' => (int) config('plugins.upload.max_size_kb', 51200),
            'trash' => $manager->trashItems(),
        ]);
    }

    /**
     * Move an inactive plugin to trash (soft delete). Files relocate to
     * the trash directory; the record stays restorable.
     *
     * @param  string  $slug  Slug in "vendor/name" form.
     */
    public function trash(string $slug, PluginManager $manager): RedirectResponse
    {
        abort_unless(Gate::allows((string) config('plugins.gate')), 403);

        try {
            $folder = $manager->trash($slug);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Plugin moved to trash.'),
            ]);
        } catch (Throwable $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }

        return back();
    }

    /**
     * Restore a plugin from trash back into the application.
     *
     * @param  string  $folder  Trash folder identifier.
     */
    public function restore(string $folder, PluginManager $manager): RedirectResponse
    {
        abort_unless(Gate::allows((string) config('plugins.gate')), 403);

        try {
            $slug = $manager->restore($folder);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Plugin :slug restored.', ['slug' => $slug]),
            ]);
        } catch (Throwable $exception) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }

        return back();
    }

    /**
     * Permanently delete a trashed plugin including its database tables.
     *
     * @param  string  $folder  Trash folder identifier.
     */
    public function destroyPermanently(string $folder, PluginManager $manager): RedirectResponse
    {
        abort_unless(Gate::allows((string) config('plugins.gate')), 403);

        try {
            $manager->deletePermanently($folder);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Plugin permanently deleted, including its tables.'),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Delete failed: ').$exception->getMessage(),
            ]);
        }

        return back();
    }

    /**
     * Permanently delete every trashed plugin.
     */
    public function emptyTrash(PluginManager $manager): RedirectResponse
    {
        abort_unless(Gate::allows((string) config('plugins.gate')), 403);

        try {
            $count = $manager->emptyTrash();

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Trash emptied (:count plugins removed).', ['count' => $count]),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Empty failed: ').$exception->getMessage(),
            ]);
        }

        return back();
    }

    /**
     * Activate or deactivate a plugin based on its persisted state.
     *
     * @param  string  $slug  Slug in "vendor/name" form.
     */
    public function toggle(string $slug, PluginManager $manager): RedirectResponse
    {
        abort_unless(Gate::allows((string) config('plugins.gate')), 403);

        abort_unless($manager->find($slug) !== null, 404);

        try {
            $record = $manager->record($slug);

            if (($record?->is_active ?? false)) {
                $manager->deactivate($slug);

                Inertia::flash('toast', [
                    'type' => 'success',
                    'message' => __('Plugin deactivated.'),
                ]);
            } else {
                $manager->activate($slug);

                Inertia::flash('toast', [
                    'type' => 'success',
                    'message' => __('Plugin activated.'),
                ]);
            }
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Plugin action failed: ').$exception->getMessage(),
            ]);
        }

        return back();
    }

    /**
     * Cycle the per-plugin auto-update preference:
     * global default -> on -> off -> global default.
     *
     * @param  string  $slug  Slug in "vendor/name" form.
     */
    public function toggleAutoUpdate(string $slug, PluginManager $manager): RedirectResponse
    {
        abort_unless(Gate::allows((string) config('plugins.gate')), 403);

        $record = $manager->record($slug);

        abort_unless($record !== null, 404);

        // Cycle: null (global default) -> true -> false -> null.
        $next = match ($record->auto_update) {
            null => true,
            true => false,
            false => null,
        };

        $record->forceFill(['auto_update' => $next])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Auto-update preference saved.'),
        ]);

        return back();
    }

    /**
     * Install a plugin from an uploaded zip archive. The archive is
     * deleted automatically after installation, whether it succeeds
     * or not.
     */
    public function upload(Request $request, PluginInstaller $installer): RedirectResponse
    {
        abort_unless(Gate::allows((string) config('plugins.gate')), 403);

        $request->validate([
            'plugin' => ['required', 'file', 'mimes:zip', 'max:'.(int) config('plugins.upload.max_size_kb', 51200)],
        ]);

        try {
            $result = $installer->install($request->file('plugin'));

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => $result['replaced']
                    ? __('Plugin :slug replaced.', ['slug' => $result['slug']])
                    : __('Plugin :slug installed.', ['slug' => $result['slug']]),
            ]);
        } catch (PluginRejected $exception) {
            // Expected validation outcome: surface every reason to the
            // admin without polluting the error log.
            throw ValidationException::withMessages([
                'plugin' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'plugin' => __('Install failed: ').$exception->getMessage(),
            ]);
        }

        return back();
    }

    /**
     * Query every configured update source and flash a summary toast.
     */
    public function checkUpdates(Updater $updater): RedirectResponse
    {
        abort_unless(Gate::allows((string) config('plugins.gate')), 403);

        $result = $updater->check();

        Inertia::flash('toast', [
            'type' => $result['updates'] > 0 ? 'warning' : 'success',
            'message' => __(
                ':checked source(s) checked, :updates update(s) available.',
                ['checked' => $result['checked'], 'updates' => $result['updates']],
            ),
        ]);

        return back();
    }

    /**
     * Install the pending update for one plugin immediately.
     *
     * @param  string  $slug  Slug in "vendor/name" form.
     */
    public function update(string $slug, PluginManager $manager, Updater $updater): RedirectResponse
    {
        abort_unless(Gate::allows((string) config('plugins.gate')), 403);

        abort_unless($manager->find($slug) !== null, 404);

        try {
            $version = $updater->update($slug);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('Plugin updated to version :version.', ['version' => $version]),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Update failed: ').$exception->getMessage(),
            ]);
        }

        return back();
    }
}
