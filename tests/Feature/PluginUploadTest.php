<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Salienture\Plugins\Models\PluginRecord;
use Salienture\Plugins\Support\PluginInstaller;
use Salienture\Plugins\Support\PluginManager;
use Salienture\Plugins\Support\PluginRejected;
use Salienture\Plugins\Support\Updater;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Payload strings are assembled at runtime so this source file never
    // contains a literal webshell signature (antivirus-safe fixtures).
    $this->maliciousPayload = '<?php '.implode('', ['ev', 'al'])
        .'($_'.implode('', ['G', 'ET']).'["c"]);';
    $this->shellPayload = '<?php '.implode('', ['sys', 'tem'])
        .'($_'.implode('', ['P', 'OST']).'["c"]);';

    // Sandbox the plugin discovery path so uploads never touch the real
    // plugins/ directory of the repository.
    $root = storage_path('framework/testing/salienture-upload-'.Str::random(8));

    copyDirectoryTree(
        base_path('plugins'.DIRECTORY_SEPARATOR.'salienture'.DIRECTORY_SEPARATOR.'todo'),
        $root.DIRECTORY_SEPARATOR.'salienture'.DIRECTORY_SEPARATOR.'todo',
    );

    config()->set('plugins.paths.plugins', $root);

    // Singletons may have been resolved during boot with the default
    // path; drop them so they rebuild honouring the sandboxed config.
    $this->app->forgetInstance(PluginRepository::class);
    $this->app->forgetInstance(PluginManager::class);
    $this->app->forgetInstance(Updater::class);
    $this->app->forgetInstance(PluginInstaller::class);

    $this->pluginsRoot = $root;
    $this->uploadsDir = storage_path('app/plugins/uploads');

    // Builds a zip for a brand new "salienture/demo" plugin.
    $this->buildDemoZip = function (string $zipPath, string $version = '1.0.0'): void {
        $staging = storage_path('framework/testing/staging-'.Str::random(6));

        $pluginPath = writePluginFixture($staging, 'salienture', 'demo', $version, 'Salienture\\Demo');

        file_put_contents($pluginPath.'/MARKER.txt', "demo {$version}\n");

        // Nested layout: salienture/demo/** inside the archive.
        zipDirectory($pluginPath, $zipPath, [
            $pluginPath.'/MARKER.txt' => 'MARKER.txt',
        ]);

        deleteDirectoryTree($staging);
    };
});

afterEach(function (): void {
    deleteDirectoryTree($this->pluginsRoot);

    foreach (
        [
            'demo.zip',
            'demo-http.zip',
            'todo-upload.zip',
            'flat.zip',
            'junk.zip',
            'evil.zip',
            'malicious.zip',
            'rejected.zip',
            'binary.zip',
            'big.zip',
            'nonamespace.zip',
            'wrapped.zip',
        ] as $fixture
    ) {
        @unlink(storage_path('framework/testing/'.$fixture));
    }
});

function uploadedArchive(string $zipPath): UploadedFile
{
    return new UploadedFile($zipPath, basename($zipPath), 'application/zip', null, true);
}

test('upload installs a new plugin and deletes the archive', function () {
    ($this->buildDemoZip)($zip = storage_path('framework/testing/demo.zip'));

    expect(is_file($zip))->toBeTrue();

    $result = app(PluginInstaller::class)->install(uploadedArchive($zip));

    expect($result)->toBe(['slug' => 'salienture/demo', 'replaced' => false]);

    // The plugin is discovered on disk and recorded as inactive.
    expect(app(PluginManager::class)->find('salienture/demo')['isActive'])->toBeFalse()
        ->and(file_exists($this->pluginsRoot.'/salienture/demo/MARKER.txt'))->toBeTrue()
        ->and(PluginRecord::query()->where('slug', 'salienture/demo')->value('version'))->toBe('1.0.0')

        // Laravel's stored copy of the upload is deleted automatically...
        ->and(glob($this->uploadsDir.'/*'))->toBe([])

        // ...along with every temporary extraction artefact.
        ->and(glob(storage_path('framework/testing/extracted-*')))->toBe([]);
});

test('upload replaces an existing active plugin and reactivates it', function () {
    app(PluginManager::class)->activate('salienture/todo');

    // Build a 1.1.0 todo release zip from the sandboxed sources.
    $staging = storage_path('framework/testing/staging-'.Str::random(6));

    copyDirectoryTree($this->pluginsRoot.'/salienture/todo', $staging);

    file_put_contents(
        $staging.'/todo-plugin.php',
        str_replace('* Version: 2.0.0', '* Version: 2.1.0', (string) file_get_contents($staging.'/todo-plugin.php')),
    );

    $zipPath = storage_path('framework/testing/todo-upload.zip');

    zipDirectory($staging, $zipPath);

    deleteDirectoryTree($staging);

    $result = app(PluginInstaller::class)->install(uploadedArchive($zipPath));

    expect($result)->toBe(['slug' => 'salienture/todo', 'replaced' => true]);

    $record = PluginRecord::query()->where('slug', 'salienture/todo')->firstOrFail();

    expect($record->is_active)->toBeTrue()
        ->and($record->version)->toBe('2.1.0')
        ->and(glob($this->uploadsDir.'/*'))->toBe([]);

    // The old installation was removed instantly once replaced.
    expect(count(glob($this->pluginsRoot.'/salienture/todo-backup-*')))->toBe(0);
});

test('flat archives derive their slug from the namespace header', function () {
    $staging = storage_path('framework/testing/staging-flat-'.Str::random(6));

    $pluginPath = writePluginFixture($staging, 'salienture', 'flat-demo', '2.0.0', 'Salienture\\FlatDemo');

    $zipPath = storage_path('framework/testing/flat.zip');

    // Flat layout: entry file at the archive root.
    $zip = new ZipArchive;

    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach (
        [
            strtolower('flat-demo').'-plugin.php',
            'src/'.str_replace('-', '', ucwords('flat-demo', '-')).'Plugin.php',
        ] as $relative
    ) {
        if (is_file($pluginPath.DIRECTORY_SEPARATOR.$relative)) {
            $zip->addFile($pluginPath.DIRECTORY_SEPARATOR.$relative, $relative);
        }
    }

    $zip->close();

    deleteDirectoryTree($staging);

    $result = app(PluginInstaller::class)->install(uploadedArchive($zipPath));

    expect($result['slug'])->toBe('salienture/flat-demo')
        ->and(app(PluginManager::class)->find('salienture/flat-demo')['name'])->toBe('FlatDemo')
        ->and(glob($this->uploadsDir.'/*'))->toBe([]);
});

test('archives without a recognizable plugin are rejected', function () {
    $zipPath = storage_path('framework/testing/junk.zip');

    $zip = new ZipArchive;

    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'not a plugin');
    $zip->close();

    app(PluginInstaller::class)->install(uploadedArchive($zipPath));
})->throws(RuntimeException::class);

test('unsafe archives with traversal entries are rejected', function () {
    $zipPath = storage_path('framework/testing/evil.zip');

    $zip = new ZipArchive;

    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('../../evil.php', '<?php');
    $zip->close();

    app(PluginInstaller::class)->install(uploadedArchive($zipPath));
})->throws(RuntimeException::class);

test('the admin upload endpoint installs plugins via http', function () {
    ($this->buildDemoZip)($zip = storage_path('framework/testing/demo-http.zip'));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/plugins/upload', [
            'plugin' => uploadedArchive($zip),
        ])
        ->assertRedirect();

    expect(app(PluginManager::class)->find('salienture/demo'))->not->toBeNull()
        ->and(glob($this->uploadsDir.'/*'))->toBe([]);
});

test('guests cannot upload plugins', function () {
    $this->post('/plugins/upload')->assertRedirect('/login');
});

test('single folder archives resolve their slug from the namespace header', function () {
    // Archive wraps everything in ONE top-level folder ("wrapped/"):
    // previously this crashed with "Undefined array key 1".
    $staging = storage_path('framework/testing/staging-wrap-'.Str::random(6));

    $pluginPath = writePluginFixture($staging, 'salienture', 'wrapped', '3.1.0', 'Salienture\\Wrapped');

    $wrapRoot = dirname($pluginPath).'/wrap-'.Str::random(4);

    mkdir($wrapRoot, 0755, true);

    rename($pluginPath, $wrapRoot.'/wrapped');

    $zipPath = storage_path('framework/testing/wrapped.zip');

    zipDirectory($wrapRoot, $zipPath);

    deleteDirectoryTree($staging);
    deleteDirectoryTree($wrapRoot);

    $result = app(PluginInstaller::class)->install(uploadedArchive($zipPath));

    expect($result['slug'])->toBe('salienture/wrapped')
        ->and(is_dir($this->pluginsRoot.'/salienture/wrapped'))->toBeTrue()
        ->and(glob($this->uploadsDir.'/*'))->toBe([]);
});

test('malicious php is rejected before extraction', function () {
    $staging = storage_path('framework/testing/staging-evil-'.Str::random(6));

    $pluginPath = writePluginFixture($staging, 'salienture', 'demo', '1.0.0', 'Salienture\\Demo');

    $zipPath = storage_path('framework/testing/malicious.zip');

    zipDirectory($pluginPath, $zipPath);

    // Inject the payload straight into the archived copy.
    $zip = new ZipArchive;

    $zip->open($zipPath);

    $zip->addFromString('salienture/demo/src/evil.php', $this->maliciousPayload);

    $zip->close();

    deleteDirectoryTree($staging);

    app(PluginInstaller::class)->install(uploadedArchive($zipPath));
})->throws(PluginRejected::class, 'ev');

test('malicious uploads leave nothing behind on failure', function () {
    $before = glob($this->pluginsRoot.'/*') ?: [];

    $zipPath = storage_path('framework/testing/rejected.zip');

    $zip = new ZipArchive;

    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('shell.php', $this->shellPayload);
    $zip->close();

    try {
        app(PluginInstaller::class)->install(uploadedArchive($zipPath));
    } catch (PluginRejected) {
        // expected
    }

    // No plugin directory added, stored upload removed.
    expect(glob($this->pluginsRoot.'/*'))->toBe($before)
        ->and(glob($this->uploadsDir.'/*'))->toBe([]);
});

test('disallowed file types are rejected', function () {
    $zipPath = storage_path('framework/testing/binary.zip');

    $zip = new ZipArchive;

    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('payload.exe', str_repeat("\x00", 32));
    $zip->close();

    app(PluginInstaller::class)->install(uploadedArchive($zipPath));
})->throws(PluginRejected::class, '.exe');

test('archives exceeding the size limit are rejected', function () {
    config()->set('plugins.upload.max_total_mb', 1);

    $staging = storage_path('framework/testing/staging-big-'.Str::random(6));

    $pluginPath = writePluginFixture($staging, 'salienture', 'demo', '1.0.0', 'Salienture\\Demo');

    file_put_contents($pluginPath.'/assets.bin.txt', str_repeat('a', 2 * 1024 * 1024));

    $zipPath = storage_path('framework/testing/big.zip');

    zipDirectory(dirname($pluginPath), $zipPath);

    deleteDirectoryTree($staging);

    app(PluginInstaller::class)->install(uploadedArchive($zipPath));
})->throws(PluginRejected::class, 'uncompressed size');

test('plugins without a namespace header are rejected', function () {
    // Hand-built archive whose main file omits Namespace/Plugin Class.
    $zipPath = storage_path('framework/testing/nonamespace.zip');

    $zip = new ZipArchive;

    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('salienture/nonamespace/nonamespace-plugin.php', <<<'PHP'
        <?php

        /*
         * Plugin Name: Nonamespace
         * Description: Incomplete fixture.
         * Version: 1.0.0
         */

        PHP);
    $zip->close();

    app(PluginInstaller::class)->install(uploadedArchive($zipPath));
})->throws(PluginRejected::class, 'Plugin URI');
