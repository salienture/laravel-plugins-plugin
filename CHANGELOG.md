# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Plugin trash with soft-delete lifecycle: "Remove" moves an inactive
  plugin to the trash directory and soft-deletes its record; trashed
  plugins can be restored or permanently deleted (dropping their database
  tables), individually or via "Empty trash" — the running site is never
  interrupted.
- Old plugin versions are removed instantly after a successful
  replacement install or update (no leftover backup folders).

### Changed

- Upload failures now surface the exact scanner reasons inline in the
  installing card (validation errors) instead of a generic message.

### Planned

- Plugin upload from the admin area: drop-in zip installation with automatic
  vendor/name resolution (archive layout or Namespace header), backup of
  replaced installations, re-activation of previously active plugins, zip-slip
  protection, and automatic deletion of the uploaded archive afterwards.
- Pre-extraction security scan for uploads: streaming inspection of the zip
  (entry names, symlink entries, extension allow-list, nested archives,
  sensitive files, size/file-count caps) plus a PHP content scan for
  obfuscation and webshell primitives — rejected archives are never extracted.
- Live installation preview on `/admin/plugins`: an installing card inside the
  plugin grid with a progress bar and per-step status, after client-side
  structure checks (zip type, empty file, size limit).
- Search and dropdown status filtering on the plugin management page.

### Planned

- Salienture marketplace integration (browse + one-click install from `/admin/plugins`).
- Menus plugin consuming plugin `navigation()` hooks for dynamic sidebar management.
- `requires` enforcement from update manifests before installing.
- Plugin uninstall (data removal) alongside the existing deactivate-keeps-data flow.
- Rollback command restoring the newest `*-backup-*` directory.

## [1.0.0] - 2026-08-26

### Added

- WordPress-style plugin discovery from `plugins/<vendor>/<name>` with header
  parsing (`Plugin Name`, `Version`, `Update URI`, `Namespace`, ...).
- `Salienture\Plugins\Contracts\Plugin` lifecycle contract (`register`,
  `boot`, `activate`, `deactivate`, `navigation`).
- Runtime PSR-4 autoloading for active plugin classes (no composer dumps).
- Activation pipeline: migrations -> state -> routes/views/lang -> hooks and
  events; deactivation keeps data (WordPress semantics).
- Unified multi-frontend layout: plugins carry
  `resources/js/react|vue/pages` / Livewire views and pages resolve in place,
  self-namespaced as `<vendor>/<name>/<page>` — never copied into host
  resources.
- Admin area at `/admin/plugins`: activate/deactivate, per-plugin auto-update
  preference (global default -> on -> off), update-now, check-updates,
  pending-update alert, author/version/license metadata display.
- Update channel: manifest contract (URL or `path://` stream), version
  comparison, zip download/install with best-effort backup, flat or nested
  archive layouts, graceful failure when sources are unreachable.
- Console commands: `salienture:plugins:list`, `salienture:plugins:check-updates`,
  `salienture:plugins:update`. Update scheduling owned by the package provider.
- Events: `PluginActivated`, `PluginDeactivated`, `PluginUpdated`.
- Self-contained Pest suites inside the package and each plugin
  (`tests/phpunit.xml`), runnable via `./vendor/bin/pest -c ...`.
