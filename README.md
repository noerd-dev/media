# noerd/media

[![Total Downloads](https://img.shields.io/packagist/dt/noerd/media.svg)](https://packagist.org/packages/noerd/media)
[![Latest Stable Version](https://img.shields.io/packagist/v/noerd/media.svg)](https://packagist.org/packages/noerd/media)

**A media library for [noerd](https://noerd.dev) — upload, organize, and serve files across your Laravel app.**
Folders, tags, and automatic image & PDF thumbnails — multi-tenant out of the box.

For full documentation, visit [noerd.dev](https://noerd.dev).

## Key Features

- **Media Library** – Upload and manage files in a YAML-configured, searchable list view
- **Folders & Tags** – Organize media into hierarchical folders and tag them for fast retrieval
- **Thumbnails & Previews** – Automatic preview generation for images (Intervention Image) and, when Ghostscript is available, for PDFs
- **MediaResolver** – A shared contract (`MediaResolverContract`) other modules use to store uploads and resolve preview URLs without depending on Media directly
- **Multi-Tenant** – Every file is scoped to its tenant, on a dedicated `media` storage disk
- **Custom Attributes** – Attach project-specific fields via the `custom_attributes` JSON column — no module changes required

## Requirements

- A working [noerd/noerd](https://github.com/noerd-dev/noerd) installation
- PHP 8.4+ (no image extension beyond the bundled `gd` required)
- Laravel 12+
- Livewire 4+

## Quickstart

```bash
# 1. Install the package (noerd/noerd is pulled in automatically)
composer require noerd/media

# 2. Install media content, navigation, and the storage disk
php artisan noerd:install-media
```

`noerd:install-media` copies the YAML configs, registers the **Media** app, adds a dedicated `media` disk to `config/filesystems.php`, and runs the module migrations.

> **Prerequisite:** the noerd platform must already be installed (`composer require noerd/noerd && php artisan noerd:install`). The media package depends on it.

## Configuration

Files are stored on a dedicated disk, configurable via the `MEDIA_DISK` environment variable (defaults to the `media` disk added during installation):

```env
MEDIA_DISK=media
```

### PDF thumbnails (optional)

PDF thumbnails are rendered by rasterizing the first page with [Ghostscript](https://www.ghostscript.com/).
The binary is auto-detected on `$PATH` (plus the usual Homebrew locations); point the config at it
explicitly when it lives elsewhere:

```env
MEDIA_GHOSTSCRIPT_BINARY=/opt/homebrew/bin/gs
```

```bash
brew install ghostscript        # macOS
apt-get install ghostscript     # Debian/Ubuntu
```

Ghostscript is **not** required. Without it — and without the legacy `imagick` extension, which is used
as a fallback when it happens to be installed — PDFs are stored without a thumbnail and the media
library renders a file-type tile instead. Image thumbnails are unaffected; they run through Intervention
Image's GD driver.

## Image variants

The original of an upload is never touched, and the 500px thumbnail is only a tile for the library.
What a visitor of a public page receives is a size-limited **variant**:

```php
$url = app(\Noerd\Contracts\MediaResolverContract::class)->getImageUrl($mediaId);          // "web"
$url = app(\Noerd\Contracts\MediaResolverContract::class)->getImageUrl($mediaId, 'teaser');
```

The URL points at the signed route `/media/image/{id}/{variant}`. On its first request the image is
scaled down to the configured width (never upscaled), encoded as WebP and cached in the hidden
`{tenant}/.variants` directory; afterwards it is only streamed, with an `immutable` cache header.
The route needs no login — the signature is the authorization, so no media id can be guessed — and
also works while `media.private` is on. SVG, GIF, AVIF and PDF files are delivered as they are.

```php
// config/media.php
'variants' => [
    'web' => 1920,      // name => maximum width in pixels
    'teaser' => 640,
],
'variant_quality' => 82,
'variant_max_pixels' => 40_000_000,   // larger images are delivered as the original
```

Run `php artisan media:clear-variants` after changing a width.

## App folders

A module can own folders in every tenant's media library — the accounting module, for example,
registers **Receipt Import** (what its receipt agent reads) and **Receipt Documents** (where the
receipts end up). Register them from the module's service provider `boot()`:

```php
app(\Noerd\Media\Services\AppFolderRegistry::class)
    ->register('accounting.receipt_import', 'ACCOUNTING', 'Receipt Import');
```

- The folder is created for every tenant holding the app: when the app is assigned to a tenant
  (`TenantAppAssigned`), when the library is opened, by `noerd:install-media` / `noerd:update-media`
  for all tenants, and on demand through `AppFolderService::resolve($tenantId, 'accounting.receipt_import')`
  — a module files a document into the resolved folder by setting the media's `folder_id`
- The label is an English translation key of the registering module (translated in its `de.json`)
  and rendered through `MediaFolder::label()`
- App folders cannot be renamed, moved or deleted (`SystemFolderProtectedException`, no delete
  button in the library); users may create sub-folders inside them unless a tenant admin blocked
  that (see "Flat folders")
- Users who may not use the owning app — not assigned to the tenant, or denied by the app
  permission, e.g. a noerd-plus grant — do not see the folder, its sub-folders or the files in
  them. A global scope on `MediaFolder` and `Media` (`AppFolderVisibilityScope`) covers the
  library, the search, the folder picker and the file routes. Console commands and queue workers
  are not filtered; a service acting for a tenant lifts the scopes with `withoutGlobalScopes()`
  and an explicit tenant id

## Blocking sub-folders

Some folders have to stay flat — an import inbox whose pipeline only reads the top level, a drop
folder a consumer watches. A **tenant admin** decides that per folder: open the folder and untick
**"Allow subfolders"** right of the breadcrumb. It works for app folders exactly like for a user's
own folder.

- The setting is the column `media_folders.allows_subfolders` (default `true`); read it through
  `MediaFolder::allowsSubfolders()`
- Blocking removes nothing: sub-folders that already exist stay, with their files. Only new ones
  are refused, so a folder can be blocked at any time
- Creating a folder inside a blocked folder — or moving one in from elsewhere — throws
  `SubfoldersNotAllowedException`. The rule lives on the model, so the library, the folder-create
  modal, a move and tinker are covered alike. Children moving up *within* the blocked folder (the
  delete cascade) are unaffected
- Everyone sees a lock icon on such a folder's tile; inside it there is no "New folder" tile

## Artisan Commands

```bash
php artisan noerd:install-media          # Install configs, navigation, storage disk and migrations
php artisan noerd:update-media           # Update the published YAML configuration files
php artisan media:regenerate-thumbnails  # Regenerate thumbnails for existing media
php artisan media:clear-variants         # Delete the cached image delivery variants
```

## Auto installed packages

- `intervention/image` — image manipulation and thumbnail generation
- `barryvdh/laravel-dompdf` — PDF rendering

Optional: `ext-imagick` — legacy fallback for PDF preview rasterization, superseded by the Ghostscript binary.

## Installation as Submodule to contribute

The submodule install is **optional** — only needed if you want to contribute to the development of Media. Install it as a git submodule instead:

```bash
git submodule add git@github.com:noerd-dev/media.git app-modules/media
```

Then add a path repository and the package to your `composer.json`:

```json
"repositories": [
    {
        "type": "path",
        "url": "app-modules/media",
        "options": {
            "symlink": true
        }
    }
],
"require": {
    "noerd/media": "*"
}
```

Then run:

```bash
composer update noerd/media
php artisan noerd:install-media
```

This way, you can make changes directly in `app-modules/media` and push them back to the Media repository.
