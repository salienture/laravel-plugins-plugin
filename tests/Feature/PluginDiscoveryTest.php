<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Salienture\Plugins\Support\PluginManager;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

test('discovers the shipped todo plugin and parses its wordpress style header', function () {
    $manager = app(PluginManager::class);

    $plugin = $manager->find('salienture/todo');

    expect($plugin)->not->toBeNull()
        ->and($plugin['name'])->toBe('Todo')
        ->and($plugin['description'])->toContain('reference implementation')
        ->and($plugin['version'])->toBe('2.0.0')
        ->and($plugin['author'])->toBe('Salienture')
        ->and($plugin['license'])->toBe('MIT')
        ->and($plugin['textDomain'])->toBe('salienture-todo')
        ->and($plugin['requiresPhp'])->toBe('8.3')
        ->and($plugin['requiresLaravel'])->toBe('13.0')
        ->and($plugin['isActive'])->toBeFalse()
        ->and($plugin['hasUpdateSource'])->toBeTrue();
});

test('admin can open the plugins management page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/plugins')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/plugins')
            ->where('canManagePlugins', true)
            ->has('plugins', 2)
            ->where('plugins.0.slug', 'salienture/notes')
            ->where('plugins.1.slug', 'salienture/todo'),
        );
});

test('guests cannot open the plugins management page', function () {
    $this->get('/admin/plugins')->assertRedirect('/login');
});

test('toggling activates the plugin, runs its migrations and deactivation keeps data', function () {
    $user = User::factory()->create();

    expect(Schema::hasTable('todos'))->toBeFalse();

    $this->actingAs($user)->post('/admin/plugins/salienture/todo/toggle');

    expect(app(PluginManager::class)->find('salienture/todo')['isActive'])->toBeTrue()
        ->and(Schema::hasTable('todos'))->toBeTrue();

    // Deactivate again - WordPress semantics: keep data around.
    $this->actingAs($user)->post('/admin/plugins/salienture/todo/toggle');

    expect(app(PluginManager::class)->find('salienture/todo')['isActive'])->toBeFalse()
        ->and(Schema::hasTable('todos'))->toBeTrue();
});

test('toggling an unknown slug 404s', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/admin/plugins/salienture/missing/toggle')
        ->assertNotFound();
});
