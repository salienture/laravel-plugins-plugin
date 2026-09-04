<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Salienture\Plugins\Models\PluginRecord;
use Salienture\Plugins\Support\PluginInstaller;
use Salienture\Plugins\Support\PluginManager;
use Salienture\Plugins\Support\PluginRepository;
use Salienture\Plugins\Support\Updater;
use Salienture\Todo\Models\Todo;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Sandbox the plugin discovery path so trash operations never touch
    // the real plugins/ directory of the repository.
    $root = storage_path('framework/testing/salienture-trash-'.Str::random(8));

    copyDirectoryTree(
        base_path('plugins'.DIRECTORY_SEPARATOR.'salienture'.DIRECTORY_SEPARATOR.'todo'),
        $root.DIRECTORY_SEPARATOR.'salienture'.DIRECTORY_SEPARATOR.'todo',
    );

    config()->set('plugins.paths.plugins', $root);

    $this->app->forgetInstance(PluginRepository::class);
    $this->app->forgetInstance(PluginManager::class);
    $this->app->forgetInstance(Updater::class);
    $this->app->forgetInstance(PluginInstaller::class);

    $this->pluginsRoot = $root;
    $this->trashPath = storage_path('app/plugins/trash');

    if (is_dir($this->trashPath)) {
        deleteDirectoryTree($this->trashPath);
    }
});

afterEach(function (): void {
    deleteDirectoryTree($this->pluginsRoot);

    if (is_dir($this->trashPath)) {
        deleteDirectoryTree($this->trashPath);
    }
});

test('an active plugin cannot be trashed', function () {
    app(PluginManager::class)->activate('salienture/todo');

    app(PluginManager::class)->trash('salienture/todo');
})->throws(RuntimeException::class, 'Deactivate');

test('trash moves the plugin out of the app and keeps it restorable', function () {
    $manager = app(PluginManager::class);

    $manager->activate('salienture/todo');
    $user = User::factory()->create();
    Todo::query()->create(['user_id' => $user->id, 'title' => 'Keep me']);

    $manager->deactivate('salienture/todo');

    $folder = $manager->trash('salienture/todo');

    // Gone from discovery, present in trash, data table untouched.
    expect($manager->find('salienture/todo'))->toBeNull()
        ->and(is_dir($this->trashPath.DIRECTORY_SEPARATOR.$folder))->toBeTrue()
        ->and(Schema::hasTable('todos'))->toBeTrue()
        ->and(PluginRecord::withTrashed()->where('slug', 'salienture/todo')->first()->deleted_at)->not->toBeNull();

    // Restore puts everything back.
    $slug = $manager->restore($folder);

    expect($slug)->toBe('salienture/todo')
        ->and(is_dir($this->pluginsRoot.'/salienture/todo'))->toBeTrue()
        ->and($manager->find('salienture/todo')['isActive'])->toBeFalse()
        ->and(PluginRecord::withTrashed()->where('slug', 'salienture/todo')->first()->deleted_at)->toBeNull();
});

test('emptying the trash removes files, record and database tables', function () {
    $manager = app(PluginManager::class);

    $manager->activate('salienture/todo');
    $user = User::factory()->create();
    Todo::query()->create(['user_id' => $user->id, 'title' => 'Doomed']);

    $manager->deactivate('salienture/todo');

    $folder = $manager->trash('salienture/todo');

    expect(Schema::hasTable('todos'))->toBeTrue();

    $removed = $manager->emptyTrash();

    expect($removed)->toBe(1)
        ->and($manager->trashItems())->toBe([])
        ->and(is_dir($this->trashPath.DIRECTORY_SEPARATOR.$folder))->toBeFalse()
        ->and(Schema::hasTable('todos'))->toBeFalse()
        ->and(PluginRecord::withTrashed()->where('slug', 'salienture/todo')->exists())->toBeFalse();
});

test('delete permanently removes a single trashed plugin', function () {
    $manager = app(PluginManager::class);

    $manager->activate('salienture/todo');
    $manager->deactivate('salienture/todo');

    $folder = $manager->trash('salienture/todo');

    $manager->deletePermanently($folder);

    expect($manager->trashItems())->toBe([])
        ->and(Schema::hasTable('todos'))->toBeFalse()
        ->and(is_dir($this->pluginsRoot.'/salienture/todo'))->toBeFalse();
});

test('restoring fails when a live installation already exists', function () {
    $manager = app(PluginManager::class);

    $manager->activate('salienture/todo');
    $manager->deactivate('salienture/todo');

    $folder = $manager->trash('salienture/todo');

    // Recreate a live installation at the same slug (e.g. fresh upload).
    copyDirectoryTree(
        base_path('plugins'.DIRECTORY_SEPARATOR.'salienture'.DIRECTORY_SEPARATOR.'todo'),
        $this->pluginsRoot.DIRECTORY_SEPARATOR.'salienture'.DIRECTORY_SEPARATOR.'todo',
    );
    $manager->flush();

    $manager->restore($folder);
})->throws(RuntimeException::class, 'already exists');
