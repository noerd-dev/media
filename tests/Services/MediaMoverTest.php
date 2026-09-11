<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Services\MediaMover;
use Noerd\Models\NoerdUser;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    $this->user = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
    $this->actingAs($this->user);
    $this->tenantId = (int) $this->user->selected_tenant_id;
    $this->mover = app(MediaMover::class);
});

/** A media row whose file really exists on the faked disk. */
function zzStoredMedia(int $tenantId, ?int $folderId, string $name, string $contents = 'FILE'): Media
{
    $media = Media::factory()->file($tenantId, $name, $folderId)->create();
    Storage::disk('media')->put($media->path, $contents);

    return $media;
}

it('moves the bytes with the record', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Rechnungen']);
    $media = zzStoredMedia($this->tenantId, null, 'beleg.pdf');
    $oldPath = $media->path;

    $this->mover->moveToFolder($media, $folder);

    expect($media->fresh()->path)->toBe("{$this->tenantId}/Rechnungen/beleg.pdf");
    Storage::disk('media')->assertExists("{$this->tenantId}/Rechnungen/beleg.pdf");
    Storage::disk('media')->assertMissing($oldPath);
});

it('renames a file the target folder already holds', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Rechnungen']);
    zzStoredMedia($this->tenantId, $folder->id, 'beleg.pdf', 'FIRST');
    $second = zzStoredMedia($this->tenantId, null, 'beleg.pdf', 'SECOND');

    $this->mover->moveToFolder($second, $folder);

    expect($second->fresh()->name)->toBe('beleg-2.pdf');
    expect(Storage::disk('media')->get("{$this->tenantId}/Rechnungen/beleg.pdf"))->toBe('FIRST');
    expect(Storage::disk('media')->get("{$this->tenantId}/Rechnungen/beleg-2.pdf"))->toBe('SECOND');
});

it('moves a file back to the tenant root', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Rechnungen']);
    $media = zzStoredMedia($this->tenantId, $folder->id, 'beleg.pdf');

    $this->mover->moveToFolder($media, null);

    expect($media->fresh()->path)->toBe("{$this->tenantId}/beleg.pdf")
        ->and($media->fresh()->folder_id)->toBeNull();
    Storage::disk('media')->assertExists("{$this->tenantId}/beleg.pdf");
});

it('carries every file below a renamed folder along', function (): void {
    $parent = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Rechnungen']);
    $child = MediaFolder::create(['tenant_id' => $this->tenantId, 'parent_id' => $parent->id, 'name' => '2026']);
    $media = zzStoredMedia($this->tenantId, $child->id, 'beleg.pdf');

    $parent->name = 'Belege';
    $parent->save();
    $this->mover->relocateFolder($parent);

    expect($media->fresh()->path)->toBe("{$this->tenantId}/Belege/2026/beleg.pdf");
    Storage::disk('media')->assertExists("{$this->tenantId}/Belege/2026/beleg.pdf");
    Storage::disk('media')->assertMissing("{$this->tenantId}/Rechnungen/2026/beleg.pdf");
});

it('deletes the generated thumbnail along with the file', function (): void {
    $media = zzStoredMedia($this->tenantId, null, 'photo.jpg');
    $thumbnail = "{$this->tenantId}/.thumbnails/thumb_photo.jpg";
    Storage::disk('media')->put($thumbnail, 'THUMB');
    $media->forceFill(['thumbnail' => $thumbnail])->save();

    $this->mover->deleteFile($media);

    Storage::disk('media')->assertMissing($media->path);
    Storage::disk('media')->assertMissing($thumbnail);
});

it('creates the directory of an empty folder', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Leer']);

    $this->mover->ensureDirectory($this->tenantId, $folder);

    expect(Storage::disk('media')->directories((string) $this->tenantId))
        ->toContain("{$this->tenantId}/Leer");
});

it('leaves the thumbnail directory alone when pruning', function (): void {
    Storage::disk('media')->makeDirectory("{$this->tenantId}/.thumbnails");
    Storage::disk('media')->makeDirectory("{$this->tenantId}/Leer");

    $this->mover->pruneEmptyDirectories($this->tenantId);

    $directories = Storage::disk('media')->directories((string) $this->tenantId);

    expect($directories)->toContain("{$this->tenantId}/.thumbnails")
        ->and($directories)->not->toContain("{$this->tenantId}/Leer");
});

it('survives a record whose file is already gone', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Rechnungen']);
    $media = Media::factory()->file($this->tenantId, 'weg.pdf', null)->create();

    $this->mover->moveToFolder($media, $folder);

    expect($media->fresh()->path)->toBe("{$this->tenantId}/Rechnungen/weg.pdf");
});

it('keeps the file extension when the record name lacks it', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Bilder']);

    // Rows written before the disk mirrored the library hold the name without
    // its extension while the path carries it.
    $media = Media::factory()->create([
        'tenant_id' => $this->tenantId,
        'name' => 'pilates',
        'extension' => 'jpg',
        'path' => "{$this->tenantId}/pilates.jpg",
        'disk' => 'media',
        'size' => 4,
    ]);
    Storage::disk('media')->put($media->path, 'FILE');

    $this->mover->moveToFolder($media, $folder);

    expect($media->fresh()->path)->toBe("{$this->tenantId}/Bilder/pilates.jpg")
        ->and($media->fresh()->name)->toBe('pilates.jpg');
    Storage::disk('media')->assertExists("{$this->tenantId}/Bilder/pilates.jpg");
});

it('does not append the extension twice', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Bilder']);
    $media = zzStoredMedia($this->tenantId, null, 'photo.JPG');
    $media->forceFill(['extension' => 'jpg'])->save();

    $this->mover->moveToFolder($media, $folder);

    expect($media->fresh()->name)->toBe('photo.JPG');
});
