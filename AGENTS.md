# AGENTS.md — noerd/media

Contributor notes for humans and AI agents working on the Media module. The rules for
building WITH noerd (lists, details, pages, modals, modules, tests) come from the `noerd/noerd`
Boost guideline and skills; the module-specific rules are in
`resources/boost/guidelines/core.blade.php`. Both are rendered into the host project's agent files
by `php artisan boost:update` (add `noerd/media` to the `packages` array in `boost.json`).

## What this module is

A multi-tenant media library on the noerd framework: uploads through the core dropzone, folders,
tags, generated thumbnails for images (Intervention Image) and PDFs (Ghostscript, optional), an
optional private mode that streams files through authenticated routes, and two seams other modules
build on without depending on Media directly — the core `MediaResolverContract` (preview URLs,
picker, storing uploads), the `MediaUsageRegistry` (a module says why a file must not be
deleted) and the `AppFolderRegistry` (a module owns a protected folder in every tenant's library,
visible only to users who may use that app). Tables: `medias`, `media_folders`, `media_tags`,
`media_tag_media`; the tenant app name is `MEDIA`.

## Layout

- `app-configs/media/` — YAML templates (`lists/media-list.yml`, `navigation.yml`); the installed
  copy lives in the host's `app-configs/media/` — change both
- `app-configs/stubs/add_media_tenant_app.php.stub` — the idempotent tenant-app migration
  published by `noerd:install-media`
- `config/media.php` — `disk`, `private`, `allowed_extensions`, `max_upload_size`,
  `ghostscript_binary`, `variants` / `variant_quality` / `variant_max_pixels`; merged by the
  provider, copied into the host by the install command
- `resources/views/components/` — Livewire single-file components, flat, Livewire namespace
  `media::` (`media-list`, `folder-create`, `folder-picker`), the Blade partials `media-thumbnail`
  and `partials/folder-tree-node`, the app icon `icons/app`
- `src/Models/` (`Media`, `MediaFolder`, `MediaTag`), `src/Services/` (`MediaResolver`,
  `MediaUploadService`, `ImagePreviewService`, `ImageVariantService` (size-limited delivery
  variants behind the signed `media.image` route), `PdfThumbnailGenerator`, `MediaUsageRegistry`,
  `AppFolderRegistry`, `AppFolderService`, `AppFolderAccess`, `MediaPathService`, `MediaMover`),
  `src/Scopes/AppFolderVisibilityScope.php`, `src/Support/FileTypeIcon.php` (the icon a file gets
  when it has no preview),
  `src/Listeners/EnsureAppFoldersOnAppAssignment.php`, `src/Exceptions/` (`MediaInUseException`,
  `SystemFolderProtectedException`, `SubfoldersNotAllowedException`), `src/Http/Controllers/MediaFileController.php`,
  `src/Commands/`, `src/Providers/MediaServiceProvider.php`
- `routes/media-routes.php`, `database/migrations|factories/`, `tests/` (Pest),
  `resources/lang/de.json`

## Commands

- `php artisan noerd:install-media` — first installation (adds the `media` disk to
  `config/filesystems.php`, publishes `config/media.php`, asks for the tenant assignment)
- `php artisan noerd:update-media` — idempotent YAML update, discovered by `noerd:update-all`
- `php artisan media:regenerate-thumbnails [--missing|--all|--id=]` — rebuild thumbnails
- `php artisan noerd:media-relocate --to=private|public` — move files when toggling `media.private`
- `php artisan media:restructure [--tenant=] [--dry-run]` — one-time move from the historic flat
  layout into the folder-mirroring one
- `php artisan media:sync [--tenant=] [--prune] [--dry-run]` — reconcile library and disk
- `php artisan media:clear-variants [--tenant=]` — drop the cached image delivery variants

## Working on the module

- Tests bind the host `Tests\TestCase`: `php artisan test --compact app-modules/media/tests`
  (Pest; tests prove mechanics, never the current YAML configuration). Always `Storage::fake('media')`;
  fake Ghostscript with `tests/Support/FakeGhostscript` instead of requiring a renderer on the host
- `Noerd\Media\Tests\` stays in the production `autoload` of `composer.json`: a path-repository's
  `autoload-dev` is not loaded by the host
- Format from the host project root with an explicit path: `vendor/bin/pint app-modules/media`
  (a plain `--dirty` run silently skips submodule files)
- Keep the module independent of other optional modules (`ModuleBoundaryTest`): Media never learns
  who uses a file or owns a folder — consumers register with `MediaUsageRegistry` /
  `AppFolderRegistry` and talk to the core contract; tests use the made-up app of
  `tests/Support/CreatesAppFolderFixtures`.
  Project-specific fields go into `custom_attributes`, never into module code or module YAML
- Upload limits and formats are configuration (`config/media.php`), not code
- Whether a folder takes NEW sub-folders is a per-folder admin setting
  (`media_folders.allows_subfolders`), enforced on the model — not a module declaration and not a
  config key. Blocking never removes existing sub-folders
- The disk mirrors the library (`{tenant}/{folders}/{name}`): build paths only through
  `MediaPathService` and move bytes only through `MediaMover` — never `Storage::move()`/`delete()`
  on a media file, and never a hand-built path. Folder changes are not model events, so every call
  site calls the mover explicitly
- When a feature changes: update the YAML in both places, `config/media.php` (module + host copy),
  `resources/lang/de.json`, the tests, `resources/boost/guidelines/core.blade.php` and `README.md`
- Releasing: bump `"version"` in `composer.json` to the tag in the tagged commit
