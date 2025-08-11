## Media Module

Das Media Modul stellt eine Medienbibliothek mit Upload, Thumbnails/Previews, Labeling/Filterung und einer Auswahl-Modal bereit.

### Features
- Upload von Bildern und PDFs
- Automatische Thumbnails (JPEG) für Bilder; PDF-Preview optional via Imagick
- Labels anlegen/zuweisen und nach Labels filtern (AND-Logik bei Mehrfachauswahl)
- Endless Scrolling in der Übersicht
- Auswahl-Modal (`media-select-modal`) zur Wiederverwendung vorhandener Medien
- `Media::preview_url` Accessor für konsistente Bild-URLs

### Voraussetzungen
- `php artisan storage:link` ausgeführt
- Für PDF-Preview: `Imagick` inkl. Ghostscript (optional)

### Konfiguration
1) In `config/filesystems.php` die Disk `images` hinzufügen:

```php
'images' => [
    'driver' => 'local',
    'root' => storage_path('app/public/images'),
    'url' => env('APP_URL') . '/storage/images',
    'visibility' => 'public',
    'throw' => false,
],
```

2) ENV setzen:

```env
MEDIA_DISK=images
APP_URL=https://example.test
```

3) Konfiguration (wird über Service Provider geladen):

`app-modules/media/config/media.php`

```php
return [
    'disk' => env('MEDIA_DISK', 'images'),
];
```

4) Migrationen ausführen und Symlink setzen:

```bash
php artisan migrate
php artisan storage:link
```

### Speicherstruktur
- Original: `{tenant_id}/{random}_{originalname.ext}`
- Thumbnail (Bilder): `{tenant_id}/thumbnails/thumb_{random}.jpg`
- Preview (PDF): `{tenant_id}/thumbnails/pdf_{random}.jpg`

### Nutzung
Komponente in Blade einbinden:

```blade
<livewire:media-table />
```

Im Auswahlmodus (z. B. im Modal):

```blade
<livewire:media-table :hideDetail="true" :selectMode="true" :selectContext="$context" />
```

Event bei Auswahl: `mediaSelected` mit `(mediaId, selectContext)`.

Services:
- `Noerd\Media\Services\MediaUploadService`
  - `storeFromArray(array $file): Media`
  - `storeFromUploadedFile($uploadedFile): Media`
  - `publicUrl(Media $media): string`

### Troubleshooting
- Kein Bild sichtbar: Prüfe `MEDIA_DISK`, `APP_URL`, `php artisan storage:link`.
- PDF-Previews fehlen: Imagick/Ghostscript installieren oder auf Bild-Thumbnails beschränken.


