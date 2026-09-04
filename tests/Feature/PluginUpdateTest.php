<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Salienture\Plugins\Models\PluginRecord;
use Salienture\Plugins\Support\PluginManager;
use Salienture\Plugins\Support\Updater;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Sandbox the plugin discovery path so update tests never touch
    // the real plugins/ directory of the repository.
    $root = storage_path('framework/testing/salienture-plugins-'.Str::random(8));

    copyDirectoryTree(
        base_path('plugins'.DIRECTORY_SEPARATOR.'salienture'.DIRECTORY_SEPARATOR.'todo'),
        $root.DIRECTORY_SEPARATOR.'salienture'.DIRECTORY_SEPARATOR.'todo',
    );

    config()->set('plugins.paths.plugins', $root);

    $this->pluginsRoot = $root;
    $this->manifestUrl = 'https://marketplace.salienture.com/api/plugins/salienture/todo.json';
    $this->zipPath = storage_path('framework/testing/todo-2.1.0.zip');

    // Builds a fake 2.1.0 release zip from the sandboxed plugin sources.
    $this->buildUpdateZip = function (): void {
        $staging = storage_path('framework/testing/staging-'.Str::random(6));

        copyDirectoryTree($this->pluginsRoot.'/salienture/todo', $staging);

        file_put_contents(
            $staging.'/todo-plugin.php',
            str_replace(
                '* Version: 2.0.0',
                '* Version: 2.1.0',
                (string) file_get_contents($staging.'/todo-plugin.php'),
            ),
        );

        file_put_contents($staging.'/RELEASE-NOTES.txt', "2.1.0 marker\n");

        $zip = new ZipArchive;

        $zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach (
            [
                'todo-plugin.php' => 'todo-plugin.php',
                'src/TodoPlugin.php' => 'src/TodoPlugin.php',
                'src/Models/Todo.php' => 'src/Models/Todo.php',
                'src/Http/Controllers/TodoController.php' => 'src/Http/Controllers/TodoController.php',
                'routes/web.php' => 'routes/web.php',
                'database/migrations/2026_08_26_000001_create_todos_table.php' => 'database/migrations/2026_08_26_000001_create_todos_table.php',
                'resources/js/react/pages/salienture/todo/todos.tsx' => 'resources/js/react/pages/salienture/todo/todos.tsx',
                'RELEASE-NOTES.txt' => 'RELEASE-NOTES.txt',
            ] as $file => $path
        ) {
            if (is_file($staging.DIRECTORY_SEPARATOR.$file)) {
                $zip->addFile($staging.DIRECTORY_SEPARATOR.$file, $path);
            }
        }

        $zip->close();

        deleteDirectoryTree($staging);
    };
});

afterEach(function (): void {
    deleteDirectoryTree($this->pluginsRoot);

    @unlink($this->zipPath);
});

test('check-updates flags an available update from the marketplace manifest', function () {
    Http::fake([
        $this->manifestUrl => Http::response([
            'slug' => 'salienture/todo',
            'version' => '2.1.0',
            'url' => 'https://downloads.salienture.com/salienture-todo-2.1.0.zip',
            'changelog' => "- New: release notes marker\n- Fixed: nothing",
        ]),
    ]);

    $result = app(Updater::class)->check();

    expect($result)->toBe(['checked' => 1, 'updates' => 1]);

    $record = PluginRecord::query()->where('slug', 'salienture/todo')->firstOrFail();

    expect($record->update_available)->toBeTrue()
        ->and($record->latest_version)->toBe('2.1.0')
        ->and($record->changelog)->toContain('release notes marker')
        ->and($record->download_url)->toBe('https://downloads.salienture.com/salienture-todo-2.1.0.zip')
        ->and(app(Updater::class)->autoUpdateEnabled($record))->toBeTrue();
});

test('older or equal manifest versions do not flag an update', function () {
    Http::fake([
        $this->manifestUrl => Http::response([
            'slug' => 'salienture/todo',
            'version' => '1.0.0',
            'url' => 'https://downloads.salienture.com/salienture-todo-1.0.0.zip',
        ]),
    ]);

    app(Updater::class)->check();

    $record = PluginRecord::query()->where('slug', 'salienture/todo')->firstOrFail();

    expect($record->update_available)->toBeFalse()
        ->and($record->latest_version)->toBeNull();
});

test('an unreachable marketplace does not break the check', function () {
    Http::fake([
        $this->manifestUrl => Http::response([], 500),
    ]);

    expect(app(Updater::class)->check())->toBe(['checked' => 0, 'updates' => 0]);
});

test('auto-update installs a newer version and keeps the plugin active', function () {
    ($this->buildUpdateZip)();

    Http::fake([
        $this->manifestUrl => Http::response([
            'slug' => 'salienture/todo',
            'version' => '2.1.0',
            'url' => 'path://'.$this->zipPath,
            'changelog' => 'marker',
        ]),
    ]);

    // Start from an active installation at 1.0.0.
    app(PluginManager::class)->activate('salienture/todo');

    app(Updater::class)->check();

    $version = app(Updater::class)->update('salienture/todo');

    expect($version)->toBe('2.1.0');

    $record = PluginRecord::query()->where('slug', 'salienture/todo')->firstOrFail();

    expect($record->is_active)->toBeTrue()
        ->and($record->version)->toBe('2.1.0')
        ->and($record->update_available)->toBeFalse();

    $updatedHeader = (string) file_get_contents(
        $this->pluginsRoot.'/salienture/todo/todo-plugin.php',
    );

    expect($updatedHeader)->toContain('* Version: 2.1.0')
        ->and(file_exists($this->pluginsRoot.'/salienture/todo/RELEASE-NOTES.txt'))->toBeTrue();
});

test('pending auto updates respects per plugin opt out', function () {
    PluginRecord::query()->create([
        'slug' => 'salienture/todo',
        'name' => 'Todo',
        'update_available' => true,
        'auto_update' => false,
    ]);

    expect(app(Updater::class)->pendingAutoUpdates())->toHaveCount(0)
        ->and(app(Updater::class)->autoUpdateEnabled(
            PluginRecord::query()->where('slug', 'salienture/todo')->firstOrFail(),
        ))->toBeFalse();
});

test('the admin update endpoint installs pending updates', function () {
    ($this->buildUpdateZip)();

    Http::fake([
        $this->manifestUrl => Http::response([
            'slug' => 'salienture/todo',
            'version' => '2.1.0',
            'url' => 'path://'.$this->zipPath,
            'changelog' => 'marker',
        ]),
    ]);

    $user = User::factory()->create();

    // Activate through HTTP so the record exists with sane defaults.
    $this->actingAs($user)->post('/admin/plugins/salienture/todo/toggle');

    app(Updater::class)->check();

    $this->actingAs($user)
        ->post('/admin/plugins/salienture/todo/update')
        ->assertRedirect();

    expect(PluginRecord::query()->where('slug', 'salienture/todo')->value('version'))->toBe('2.1.0');
});
