# Salienture Laravel Plugins plugin

WordPress-style **plug-and-play plugin system** for Laravel apps with any
frontend stack (Inertia/React, Inertia/Vue, Livewire).

Drop a folder into `plugins/`, activate it from `/admin/plugins`, and it
contributes routes, migrations, pages and menu entries — **without touching a
single host application file**. Updates are discovered via a marketplace
manifest contract and install automatically.

- Namespace: `Salienture\Plugins\`
- Composer package: `salienture/laravel-plugins-plugin`
- Author: [Salienture](https://salienture.com)
- License: MIT

---

## Installation

Install via Composer:

```bash
composer require salienture/laravel-plugins-plugin
```

The service provider is auto-discovered by Laravel. Once installed:

1. Create a `plugins/` directory at your project root (or configure a custom path in `config/salienture-plugins.php`).
2. Drop plugin folders into `plugins/<vendor>/<name>/`.
3. Visit `/admin/plugins` to activate and manage your plugins.

### Requirements

- PHP 8.3+
- Laravel 13.0+

### Frontend setup (Inertia only)

If you use Inertia, add one resolver line in your frontend entry (`resources/js/app.tsx`) to enable plugin page resolution:

```ts
import { resolvePage } from '@/lib/inertia-pages';
```

This function globs both host and plugin page directories automatically.

## Unified plugin layout (frontend-agnostic)

One plugin zip works on every host stack. Pages are read from whichever
directory matches the host's frontend:

```
plugins/
  salienture/
    todo/
      todo-plugin.php                    <- entry file carrying the header
      src/                               <- PSR-4 classes (Namespace header -> here)
      routes/web.php                     <- loaded under `web` when active
      database/migrations/               <- run automatically on activation
      resources/js/react/pages/          <- React/Inertia hosts read here
        salienture/todo/todos.tsx
      resources/js/vue/pages/            <- Vue hosts read here
      resources/views/livewire/          <- Livewire hosts read here
      tests/                             <- Pest suite + phpunit.xml (self-contained)
      lang/                              <- translation namespace = Text Domain header
      README.md CHANGELOG.md docs/adr/
```

Pages are stored **self-namespaced**, mirroring their public Inertia component
name (`<vendor>/<name>/<page>`), so resolution is collision-free and identical
on every stack. The host resolves them in place — nothing is ever copied into
host resources.

### Plugin entry header

```php
<?php

/*
 * Plugin Name: Todo
 * Description: Personal todo lists.
 * Version: 1.0.0
 * Plugin URI: https://salienture.com/plugins/todo
 * Author: Salienture
 * Author URI: https://salienture.com
 * License: MIT
 * Text Domain: salienture-todo
 * Requires PHP: 8.3
 * Requires Laravel: 13.0
 * Update URI: https://marketplace.salienture.com/api/plugins/salienture/todo.json
 * Namespace: Salienture\Todo
 * Plugin Class: Salienture\Todo\TodoPlugin
 */

require_once __DIR__.'/src/TodoPlugin.php';
```

`Plugin Class` must implement `Salienture\Plugins\Contracts\Plugin`
(`register/boot/activate/deactivate/navigation`). If omitted, it defaults to
`{Namespace}\{StudlyName}Plugin`.

### Lifecycle

| Event | What happens |
| --- | --- |
| Every request | Active plugins get PSR-4 prefixes registered, then `register()` + `boot()` run; routes/views/lang load; page dirs join Inertia's finder. |
| Activation | State persisted, migrations run, routes register immediately, `activate()` hook + `PluginActivated` event fire. |
| Deactivation | `is_active = false`, `deactivate()` hook + event. Data/files are kept (WordPress semantics). |

## Host integration (what the core touches)

By design the core requires almost nothing from the host app:

1. Composer autoload mapping (`Salienture\Plugins\` -> `packages/plugins/src`)
   and one provider registration — this is how official packages install.
2. One resolver line in the frontend entry (`resources/js/app.tsx`) pointing at
   `resolvePage()` from `resources/js/lib/inertia-pages.ts`, which globs both
   host pages and plugin pages.

Menus are intentionally **not** injected by the core: plugins expose entries
through their `navigation()` hook and a dedicated Menus plugin (upcoming) will
manage placement. Until then links live statically in `app-sidebar.tsx`.
Scheduling of updates lives inside the package provider — no host cron wiring.

## Admin area

Visit `/admin/plugins` (authenticated + verified; gate `managePlugins`,
overridable):

- **Search** by name, slug, description or author.
- **Filter** by status: All / Active / Inactive / Updates available.
- Activate / Deactivate per plugin.
- Update-available badge with latest version and changelog.
- Update now / Check for updates actions.
- Auto-update preference per plugin cycling `global default -> on -> off`.
- **Upload plugin**: install a zip straight from the admin UI.

### Uploading plugins

Click *Upload plugin* and pick a zip archive. Accepted layouts are plugin
files at the archive root, a single top-level folder, or the canonical
`<vendor>/<name>/**` structure. The slug is taken from the archive's
directory structure or derived from the plugin's `Namespace` header
(`Salienture\Notes` -> salienture/notes).

Behaviour:

- New plugins install **inactive** (WordPress semantics) and appear in the
  list ready to activate.
- Re-uploading an existing plugin replaces it in place — the previous
  installation is backed up next to itself as `<name>-backup-*`, and if it
  was active it is re-activated automatically with its migrations re-run.
- The uploaded archive and every temporary extraction file are deleted
  automatically after installation, success or failure. Archives containing
  traversal entries (zip slip) are rejected before extraction.

## Updates & auto-updates

Update sources return a JSON manifest:

```json
{
    "slug": "salienture/todo",
    "version": "1.1.0",
    "url": "https://downloads.example.com/salienture-todo-1.1.0.zip",
    "changelog": "### 1.1.0\n- Added release notes"
}
```

Resolution order: `Update URI` header -> `path://<file>` streams (offline
testing) -> `SALIENTURE_PLUGIN_MARKETPLACE_URL` base + `/{vendor}/{name}.json`
(the future marketplace contract). Archives may be flat or single-rooted; the
previous version is backed up next to itself before swap and the plugin is
re-activated afterwards. Scheduled checks/auto-updates run from the package
itself at 03:00/03:30 unless `SALIENTURE_PLUGIN_AUTO_UPDATE=false`.

## Commands

```bash
php artisan salienture:plugins:list                  # discovered plugins + state
php artisan salienture:plugins:check-updates [slug]  # query update sources
php artisan salienture:plugins:update [slug]         # install pending updates (--force)
```

## Tests live inside each package/plugin

Every package and plugin carries its own Pest suite and `tests/phpunit.xml`
(sqlite `:memory:`), runnable without touching the host test setup:

```bash
./vendor/bin/pest -c packages/plugins/tests/phpunit.xml
./vendor/bin/pest -c plugins/salienture/todo/tests/phpunit.xml
```

Test files bind `Tests\TestCase` and `RefreshDatabase` explicitly, keeping
them fully self-contained.

## Configuration

Publishable via `php artisan vendor:publish --tag=salienture-plugins-config`.

| Key | Default | Purpose |
| --- | --- | --- |
| `paths.plugins` | `base_path('plugins')` | Discovery root |
| `frontend` | `SALIENTURE_PLUGIN_FRONTEND` (`react`) | Which page directory convention to serve |
| `marketplace.base_url` | `SALIENTURE_PLUGIN_MARKETPLACE_URL` | Fallback update source |
| `auto_update.enabled` | `SALIENTURE_PLUGIN_AUTO_UPDATE` (`true`) | Global kill switch |
| `updates.disk` | `local` | Disk for downloaded archives |
| `gate` | `managePlugins` | Ability checked for admin actions |

## Reference implementation

[`https://github.com/salienture/laravel-todo-plugin`](https://github.com/salienture/laravel-todo-plugin) demonstrates
the complete layout: header, lifecycle class, CRUD + ownership rules,
self-namespaced React page, factory-in-src convention, in-plugin Pest suite,
CHANGELOG and ADRs.
