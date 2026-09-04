<?php

namespace Salienture\Plugins\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Salienture\Plugins\Contracts\Plugin;
use Salienture\Plugins\Events\PluginActivated;
use Salienture\Plugins\Events\PluginDeactivated;
use Salienture\Plugins\Models\PluginRecord;
use Throwable;

/**
 * Central plugin lifecycle manager: discovery, activation, deactivation
 * and booting of active plugins on every request.
 *
 * Plugins stay fully self-contained on disk: pages are resolved in place
 * (never copied into host resources) and menu entries are only exposed to
 * consumers such as a dedicated Menus plugin.
 */
class PluginManager
{
    private ?Collection $discovered = null;

    /**
     * Create a new manager instance.
     *
     * @param  PluginRepository  $repository  Disk discovery.
     * @param  PluginAutoloader  $autoloader  Runtime PSR-4 loader.
     * @param  Container  $app  Application container handed to plugins.
     * @param  string  $basePath  Host application base path.
     */
    public function __construct(
        private readonly PluginRepository $repository,
        private readonly PluginAutoloader $autoloader,
        private readonly Container $app,
        private readonly string $basePath,
    ) {}

    /**
     * All plugins discovered on disk, merged with persisted state.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        return $this->discover()->map(fn (PluginManifest $manifest): array => $this->present($manifest))->values();
    }

    /**
     * Find one plugin by slug, merged with its persisted state.
     *
     * @param  string  $slug  Slug in "vendor/name" form.
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        $manifest = $this->discover()->get($slug);

        return $manifest === null ? null : $this->present($manifest);
    }

    /**
     * Fetch the persisted lifecycle/update state of a plugin.
     *
     * @param  string  $slug  Slug in "vendor/name" form.
     */
    public function record(string $slug): ?PluginRecord
    {
        return PluginRecord::query()->where('slug', $slug)->first();
    }

    /**
     * Activate a plugin: persist state, run migrations, register routes
     * and views immediately, then fire the activate hook and event.
     *
     * @param  string  $slug  Slug in "vendor/name" form.
     * @return array{already: bool} True when the plugin was already active.
     *
     * @throws RuntimeException When the plugin is not discovered on disk.
     */
    public function activate(string $slug): array
    {
        $manifest = $this->assertDiscovered($slug);

        $record = $this->syncRecord($manifest);

        if ($record->is_active) {
            return ['already' => true];
        }

        $this->runPluginMigrations($manifest);

        $record->forceFill([
            'is_active' => true,
            'version' => $manifest->version(),
        ])->save();

        // Register routes/views/translations right away so the plugin is
        // usable within the same request that activated it.
        $this->registerSingle($manifest);

        $this->instantiate($manifest)?->activate();

        Event::dispatch(new PluginActivated($slug));

        return ['already' => false];
    }

    /**
     * Deactivate a plugin. Data and files are kept (WordPress behaviour).
     *
     * @param  string  $slug  Slug in "vendor/name" form.
     * @return array{already: bool} True when the plugin was already inactive.
     *
     * @throws RuntimeException When the plugin is not discovered on disk.
     */
    public function deactivate(string $slug): array
    {
        $manifest = $this->assertDiscovered($slug);

        $record = $this->syncRecord($manifest);

        if (! $record->is_active) {
            return ['already' => true];
        }

        $record->forceFill(['is_active' => false])->save();

        $this->instantiate($manifest)?->deactivate();

        Event::dispatch(new PluginDeactivated($slug));

        return ['already' => false];
    }

    /**
     * Register routes, view/translation namespaces, Inertia page paths and
     * boot all active plugins. Called once per request by the provider.
     */
    public function registerActive(): void
    {
        $this->registerRoutes();

        $this->bootActive();
    }

    /**
     * Load web routes, views and translations of every active plugin.
     */
    public function registerRoutes(): void
    {
        foreach ($this->activeRecords() as $record) {
            $manifest = $this->discover()->get($record->slug);

            if ($manifest === null) {
                continue;
            }

            $this->registerSingle($manifest);
        }
    }

    /**
     * Register the runtime integration points of one active plugin:
     * view/translation namespaces, Inertia page discovery and web routes.
     */
    private function registerSingle(PluginManifest $manifest): void
    {
        $namespace = $manifest->headers['textDomain']
            ?? str_replace('/', '-', $manifest->slug);

        $viewPath = $manifest->path().DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';

        if (is_dir($viewPath)) {
            $this->app->make('view')->addNamespace($namespace, $viewPath);
        }

        $langPath = $manifest->path().DIRECTORY_SEPARATOR.'lang';

        if (is_dir($langPath)) {
            $this->app->make('translator')->addNamespace($namespace, $langPath);
        }

        // Make plugin pages resolvable by Inertia's component finder
        // (used for rendering checks and the testing assertions).
        $pagesPath = $manifest->pagesPath(
            (string) config('plugins.frontend', 'react'),
        );

        if ($pagesPath !== null) {
            $paths = (array) config('inertia.pages.paths', []);

            if (! in_array($pagesPath, $paths, true)) {
                config(['inertia.pages.paths' => [...$paths, $pagesPath]]);
            }
        }

        $routesFile = $manifest->path().DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php';

        if (is_file($routesFile)) {
            Route::middleware('web')->group($routesFile);
        }
    }

    /**
     * Boot all active plugins (called once per request from the provider).
     */
    public function bootActive(): void
    {
        foreach ($this->activeRecords() as $record) {
            $manifest = $this->discover()->get($record->slug);

            if ($manifest === null) {
                continue;
            }

            $sourcePath = $manifest->sourcePath();

            if ($sourcePath !== null && isset($manifest->headers['namespace'])) {
                $this->autoloader->addNamespace($manifest->headers['namespace'], $sourcePath);
            }

            $plugin = $this->instantiate($manifest);

            if ($plugin === null) {
                continue;
            }

            $plugin->register($this->app);
            $plugin->boot($this->app);
        }
    }

    /**
     * Menu entries contributed by active plugins. The core never renders
     * them; menu integrations (e.g. a Menus plugin) consume this API.
     *
     * @return array<int, array{title: string, href: string, icon?: string|null}>
     */
    public function navigationItems(): array
    {
        $items = [];

        foreach ($this->activeRecords() as $record) {
            $manifest = $this->discover()->get($record->slug);

            if ($manifest === null) {
                continue;
            }

            $plugin = $this->instantiate($manifest);

            foreach ($plugin?->navigation() ?? [] as $item) {
                $items[] = [
                    'title' => (string) ($item['title'] ?? ''),
                    'href' => (string) ($item['href'] ?? '#'),
                    'icon' => isset($item['icon']) ? (string) $item['icon'] : null,
                ];
            }
        }

        return $items;
    }

    /**
     * Run the plugin's database migrations when the directory exists.
     */
    private function runPluginMigrations(PluginManifest $manifest): void
    {
        $migrations = $manifest->path().DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';

        if (! is_dir($migrations)) {
            return;
        }

        Artisan::call('migrate', [
            '--path' => $this->relativeToBase($migrations),
            '--force' => true,
        ]);
    }

    /**
     * Turn an absolute path into one relative to the host base path,
     * normalized to forward slashes for Artisan "--path" usage.
     */
    private function relativeToBase(string $absolutePath): string
    {
        $normalized = str_replace('\\', '/', $absolutePath);
        $base = str_replace('\\', '/', $this->basePath);

        return ltrim(substr($normalized, strlen(rtrim($base, '/'))), '/');
    }

    /**
     * Move an inactive plugin to trash (soft delete): the directory is
     * relocated out of the discovery path and its record soft-deleted.
     * The website keeps running; the plugin simply disappears.
     *
     * @param  string  $slug  Slug in "vendor/name" form.
     * @return string The trash folder name created.
     *
     * @throws RuntimeException When the plugin is active or missing.
     */
    public function trash(string $slug): string
    {
        $manifest = $this->assertDiscovered($slug);

        $record = $this->record($slug)
            ?? PluginRecord::query()->firstOrNew(['slug' => $slug]);

        if ($record->is_active) {
            throw new RuntimeException(
                "Deactivate [{$slug}] before moving it to trash.",
            );
        }

        $trashPath = (string) config('plugins.paths.trash');

        if (! is_dir($trashPath)) {
            mkdir($trashPath, 0755, true);
        }

        $folder = str_replace('/', '__', $slug).'__'.now()->format('YmdHis');
        $source = $manifest->path();
        $target = $trashPath.DIRECTORY_SEPARATOR.$folder;

        gc_collect_cycles();

        if (! @rename($source, $target)) {
            usleep(200_000);

            if (! @rename($source, $target)) {
                PluginArchive::copyDirectory($source, $target);
                PluginArchive::deleteDirectory($source);
            }
        }

        $record->forceFill(['is_active' => false])->delete();

        $this->flush();

        return $folder;
    }

    /**
     * Restore a trashed plugin back into the discovery path.
     *
     * @param  string  $folder  Trash folder name returned by trash().
     * @return string The restored slug.
     *
     * @throws RuntimeException When the folder/slug is invalid or a
     *                          live installation already occupies the slug.
     */
    public function restore(string $folder): string
    {
        $slug = $this->slugFromFolder($folder);

        $trashPath = (string) config('plugins.paths.trash');
        $source = $trashPath.DIRECTORY_SEPARATOR.$folder;
        $target = (string) config('plugins.paths.plugins')
            .DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $slug);

        if (! is_dir($source)) {
            throw new RuntimeException("Trash folder [{$folder}] does not exist.");
        }

        if (is_dir($target)) {
            throw new RuntimeException("A live installation of [{$slug}] already exists; remove it first.");
        }

        gc_collect_cycles();

        if (! @rename($source, $target)) {
            usleep(200_000);

            if (! @rename($source, $target)) {
                PluginArchive::copyDirectory($source, $target);
                PluginArchive::deleteDirectory($source);
            }
        }

        PluginRecord::withTrashed()
            ->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug],
            )
            ->restore();

        $this->flush();

        return $slug;
    }

    /**
     * Permanently delete one trashed plugin: drop its tables, force-delete
     * its record and remove the trashed files.
     *
     * @param  string  $folder  Trash folder name returned by trash().
     *
     * @throws RuntimeException When table drops fail (trash is kept).
     */
    public function deletePermanently(string $folder): void
    {
        $slug = $this->slugFromFolder($folder);

        $dir = (string) config('plugins.paths.trash')
            .DIRECTORY_SEPARATOR.$folder;

        if (! is_dir($dir)) {
            throw new RuntimeException("Trash folder [{$folder}] does not exist.");
        }

        $tables = $this->pluginTables($dir);

        foreach ($tables as $table) {
            try {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                Schema::dropIfExists($table);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "Could not drop table [{$table}]: {$exception->getMessage()}; plugin kept in trash.",
                    previous: $exception,
                );
            }
        }

        PluginRecord::withTrashed()
            ->where('slug', $slug)
            ->first()
            ?->forceDelete();

        PluginArchive::deleteDirectory($dir);
    }

    /**
     * Permanently delete every trashed plugin.
     *
     * @return int Number of plugins removed.
     */
    public function emptyTrash(): int
    {
        $count = 0;

        foreach ($this->trashItems() as $item) {
            $this->deletePermanently($item['folder']);
            $count++;
        }

        return $count;
    }

    /**
     * List the contents of the plugin trash, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function trashItems(): array
    {
        $trashPath = (string) config('plugins.paths.trash');

        if (! is_dir($trashPath)) {
            return [];
        }

        $items = [];

        foreach (scandir($trashPath) ?: [] as $folder) {
            if (str_starts_with($folder, '.')) {
                continue;
            }

            $full = $trashPath.DIRECTORY_SEPARATOR.$folder;

            if (! is_dir($full)) {
                continue;
            }

            try {
                $slug = $this->slugFromFolder($folder);
            } catch (RuntimeException) {
                continue;
            }

            $entryFile = (glob($full.'/*-plugin.php') ?: [null])[0];

            $manifest = $entryFile !== null
                ? PluginManifest::parse($slug, $entryFile)
                : null;

            $items[] = [
                'folder' => $folder,
                'slug' => $slug,
                'name' => $manifest?->name() ?? $slug,
                'version' => $manifest?->version(),
                'trashedAt' => substr($folder, strrpos($folder, '__') + 2) ?: '',
            ];
        }

        usort($items, fn (array $a, array $b): int => strcmp($b['trashedAt'], $a['trashedAt']));

        return $items;
    }

    /**
     * Parse "vendor__name__timestamp" trash folder names back into slugs.
     *
     * @throws RuntimeException For malformed folder names.
     */
    private function slugFromFolder(string $folder): string
    {
        $position = strpos($folder, '__');

        if ($position === false || ! preg_match('/^([a-z0-9\-]+)__([a-z0-9\-]+)__\d{14}$/', $folder, $matches)) {
            throw new RuntimeException("Invalid trash folder name [{$folder}].");
        }

        // Slug segments are kebab/lower by construction; "__" only ever
        // separates vendor from name from timestamp.
        $withoutTimestamp = substr($folder, 0, (int) strrpos($folder, '__'));

        return str_replace('__', '/', $withoutTimestamp);
    }

    /**
     * Collect table names declared by a plugin's migrations, newest file
     * first so dependent tables are dropped before their parents.
     *
     * @return list<string>
     */
    private function pluginTables(string $directory): array
    {
        $migrations = $directory.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';

        if (! is_dir($migrations)) {
            return [];
        }

        $files = glob($migrations.'/*.php') ?: [];

        rsort($files);

        $tables = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            preg_match_all("/Schema::create\(\s*['\"]([A-Za-z0-9_]+)['\"]/", $contents, $matches);

            foreach ($matches[1] as $table) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * All persisted records currently flagged as active.
     *
     * @return Collection<int, PluginRecord>
     */
    private function activeRecords(): Collection
    {
        return PluginRecord::query()
            ->where('is_active', true)
            ->orderBy('slug')
            ->get();
    }

    /**
     * Lazily discover plugins on disk (cached per manager instance).
     *
     * @return Collection<string, PluginManifest>
     */
    private function discover(): Collection
    {
        return $this->discovered ??= $this->repository->all();
    }

    /**
     * Forget the cached discovery result so newly installed plugin
     * directories become visible within the same request.
     */
    public function flush(): void
    {
        $this->discovered = null;
    }

    /**
     * Require a plugin manifest or fail loudly.
     *
     * @throws RuntimeException When the slug is not discovered on disk.
     */
    private function assertDiscovered(string $slug): PluginManifest
    {
        $manifest = $this->discover()->get($slug);

        if ($manifest === null) {
            throw new RuntimeException("Plugin [{$slug}] is not installed.");
        }

        return $manifest;
    }

    /**
     * Fetch-or-create the persisted record for a discovered manifest.
     */
    private function syncRecord(PluginManifest $manifest): PluginRecord
    {
        return PluginRecord::query()->firstOrCreate(
            ['slug' => $manifest->slug],
            [
                'name' => $manifest->name(),
                'version' => $manifest->version(),
            ],
        );
    }

    /**
     * Instantiate the plugin main class, registering its PSR-4 prefix on
     * demand so classes resolve before composer ever sees them.
     *
     * @throws RuntimeException When the class does not implement the contract.
     */
    private function instantiate(PluginManifest $manifest): ?Plugin
    {
        $class = ltrim((string) $manifest->pluginClass(), '\\');

        if ($class === '') {
            return null;
        }

        $sourcePath = $manifest->sourcePath();

        if (! class_exists($class) && $sourcePath !== null && isset($manifest->headers['namespace'])) {
            $this->autoloader->addNamespace($manifest->headers['namespace'], $sourcePath);
        }

        if (! class_exists($class)) {
            return null;
        }

        $instance = new $class;

        if (! $instance instanceof Plugin) {
            throw new RuntimeException("Plugin class [{$class}] must implement ".Plugin::class.'.');
        }

        return $instance;
    }

    /**
     * Build the admin-facing representation of one plugin by merging its
     * header metadata with persisted lifecycle/update state.
     *
     * @return array<string, mixed>
     */
    private function present(PluginManifest $manifest): array
    {
        $record = $this->record($manifest->slug);

        return [
            'slug' => $manifest->slug,
            'name' => $manifest->name(),
            'description' => $manifest->description(),
            'version' => $manifest->version(),
            'author' => $manifest->author(),
            'authorUri' => $manifest->headers['authorUri'] ?? null,
            'pluginUri' => $manifest->headers['pluginUri'] ?? null,
            'license' => $manifest->headers['license'] ?? null,
            'textDomain' => $manifest->headers['textDomain'] ?? null,
            'requiresPhp' => $manifest->headers['requiresPhp'] ?? null,
            'requiresLaravel' => $manifest->headers['requiresLaravel'] ?? null,
            'hasUpdateSource' => $manifest->updateSource(config('plugins.marketplace.base_url')) !== null,
            'isActive' => $record?->is_active ?? false,
            'autoUpdate' => $record?->auto_update,
            'latestVersion' => $record?->latest_version,
            'changelog' => $record?->changelog,
            'updateAvailable' => $record?->update_available ?? false,
            'lastCheckedAt' => $record?->last_checked_at?->toIso8601String(),
        ];
    }
}
