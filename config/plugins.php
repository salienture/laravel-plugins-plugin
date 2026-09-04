<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plugin discovery paths
    |--------------------------------------------------------------------------
    |
    | Directories scanned for plugins (vendor/name folders containing a
    | *-plugin.php entry file), WordPress wp-content/plugins style.
    |
    */

    'paths' => [
        'plugins' => base_path('plugins'),
        'trash' => env('SALIENTURE_PLUGIN_TRASH_PATH', storage_path('app/plugins/trash')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Unified frontend conventions
    |--------------------------------------------------------------------------
    |
    | Plugins are frontend-agnostic. Depending on the host application's
    | stack, pages are read from the matching directory inside the plugin:
    |
    |   react:   plugins/<vendor>/<name>/resources/js/react/pages
    |   vue:     plugins/<vendor>/<name>/resources/js/vue/pages
    |   livewire: plugins/<vendor>/<name>/resources/views/livewire
    |
    | Inertia component names follow "<vendor>/<name>/<page>" so the same
    | plugin zip works on every stack without repackaging.
    */

    'frontend' => env('SALIENTURE_PLUGIN_FRONTEND', 'react'),

    /*
    |--------------------------------------------------------------------------
    | Marketplace / update channel
    |--------------------------------------------------------------------------
    |
    | Fallback base URL used to resolve update manifests for plugins that do
    | not declare their own `Update URI` header. The final endpoint is:
    |
    |     {base_url}/{vendor}/{name}.json
    |
    | This is the contract the official Salienture plugin marketplace serves.
    */

    'marketplace' => [
        'base_url' => env('SALIENTURE_PLUGIN_MARKETPLACE_URL', 'https://marketplace.salienture.com/api/plugins'),
        'timeout' => env('SALIENTURE_PLUGIN_MARKETPLACE_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto updates
    |--------------------------------------------------------------------------
    |
    | When enabled, updates found during scheduled checks are installed
    | automatically unless a plugin overrides the setting per-plugin
    | (`auto_update` column: true/false/null = follow default).
    */

    'auto_update' => [
        'enabled' => env('SALIENTURE_PLUGIN_AUTO_UPDATE', true),
        'default' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Update downloads
    |--------------------------------------------------------------------------
    */

    'updates' => [
        'disk' => env('SALIENTURE_PLUGIN_UPDATES_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads (admin zip installation)
    |--------------------------------------------------------------------------
    |
    | Uploaded archives are inspected as a streaming pass BEFORE anything is
    | extracted to disk: entry names (zip slip), symlink entries, extension
    | allow-list, nested archives, sensitive files, total size / file count
    | caps and a PHP content scan for obfuscation or webshell primitives.
    |
    | Rejected uploads never touch the plugin directory and the archive is
    | deleted automatically afterwards.
    */

    'upload' => [
        'max_size_kb' => env('SALIENTURE_PLUGIN_MAX_UPLOAD_KB', 51200),
        'max_files' => 2000,
        'max_total_mb' => 100,

        // File types a Salienture plugin may contain.
        'allowed_extensions' => [
            'php', 'css', 'scss', 'map',
            'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'vue', 'd.ts',
            'json', 'md', 'txt', 'xml', 'yml', 'yaml', 'csv', 'html', 'htm',
            'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'bmp',
            'woff', 'woff2', 'ttf', 'otf', 'eot',
            'mp3', 'mp4', 'webm', 'wav', 'ogg',
        ],

        // Archives inside archives are rejected outright (payload smuggling).
        'blocked_archives' => ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'phar'],

        // Patterns scanned against every .php file in the archive.
        'blocked_patterns' => [
            'eval\s*\(',
            'assert\s*\(',
            'create_function\s*\(',
            'preg_replace\s*\(\s*([\'"]).*?/e[a-z]*\1',
            'base64_decode\s*\(',
            'gzinflate\s*\(',
            'gzuncompress\s*\(',
            'str_rot13\s*\(',
            'strrev\s*\(\s*\$',
            '\bsystem\s*\(',
            '\bexec\s*\(',
            'shell_exec\s*\(',
            'passthru\s*\(',
            '\bpopen\s*\(',
            'proc_open\s*\(',
            '`[^`]+`',
            '\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Access gate
    |--------------------------------------------------------------------------
    |
    | Ability name checked before anyone may manage plugins. Define this
    | gate in your app to restrict management to certain roles.
    */

    'gate' => 'managePlugins',
];
