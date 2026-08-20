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

## Artisan Commands

```bash
php artisan noerd:install-media          # Install configs, navigation, storage disk and migrations
php artisan noerd:update-media           # Update the published YAML configuration files
php artisan media:regenerate-thumbnails  # Regenerate thumbnails for existing media
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
