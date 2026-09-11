<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Models\NoerdUser;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    $this->user = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
    $this->actingAs($this->user);
    $this->tenantId = (int) $this->user->selected_tenant_id;
});

/** A record in the historic flat layout: {tenant}/{random}_{name}. */
function zzFlatMedia(int $tenantId, ?int $folderId, string $name): Media
{
    $path = $tenantId . '/aB3xK9_' . $name;

    $media = Media::factory()->create([
        'tenant_id' => $tenantId,
        'folder_id' => $folderId,
        'name' => $name,
        'extension' => pathinfo($name, PATHINFO_EXTENSION),
        'path' => $path,
        'disk' => 'media',
        'size' => 4,
    ]);

    Storage::disk('media')->put($path, 'FILE');

    return $media;
}

it('moves a flat file into its folder directory', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Rechnungen']);
    $media = zzFlatMedia($this->tenantId, $folder->id, 'beleg.pdf');

    $this->artisan('media:restructure', ['--tenant' => $this->tenantId])->assertSuccessful();

    expect($media->fresh()->path)->toBe("{$this->tenantId}/Rechnungen/beleg.pdf");
    Storage::disk('media')->assertExists("{$this->tenantId}/Rechnungen/beleg.pdf");
    Storage::disk('media')->assertMissing("{$this->tenantId}/aB3xK9_beleg.pdf");
});

it('drops the random prefix of a file in the tenant root', function (): void {
    $media = zzFlatMedia($this->tenantId, null, 'beleg.pdf');

    $this->artisan('media:restructure', ['--tenant' => $this->tenantId])->assertSuccessful();

    expect($media->fresh()->path)->toBe("{$this->tenantId}/beleg.pdf");
});

it('writes nothing on a dry run', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Rechnungen']);
    $media = zzFlatMedia($this->tenantId, $folder->id, 'beleg.pdf');
    $original = $media->path;

    $this->artisan('media:restructure', ['--tenant' => $this->tenantId, '--dry-run' => true])
        ->expectsOutputToContain("{$this->tenantId}/Rechnungen/beleg.pdf")
        ->assertSuccessful();

    expect($media->fresh()->path)->toBe($original);
    Storage::disk('media')->assertExists($original);
});

it('moves thumbnails into the hidden directory', function (): void {
    $media = zzFlatMedia($this->tenantId, null, 'photo.jpg');
    $legacy = "{$this->tenantId}/thumbnails/thumb_photo.jpg";
    Storage::disk('media')->put($legacy, 'THUMB');
    $media->forceFill(['thumbnail' => $legacy])->save();

    $this->artisan('media:restructure', ['--tenant' => $this->tenantId])->assertSuccessful();

    expect($media->fresh()->thumbnail)->toBe("{$this->tenantId}/.thumbnails/thumb_photo.jpg");
    Storage::disk('media')->assertExists("{$this->tenantId}/.thumbnails/thumb_photo.jpg");
    Storage::disk('media')->assertMissing($legacy);
});

it('numbers two files of the same name in one folder', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Rechnungen']);
    $first = zzFlatMedia($this->tenantId, $folder->id, 'beleg.pdf');
    $second = Media::factory()->create([
        'tenant_id' => $this->tenantId,
        'folder_id' => $folder->id,
        'name' => 'beleg.pdf',
        'extension' => 'pdf',
        'path' => "{$this->tenantId}/zZ99_beleg.pdf",
        'disk' => 'media',
        'size' => 4,
    ]);
    Storage::disk('media')->put($second->path, 'SECOND');

    $this->artisan('media:restructure', ['--tenant' => $this->tenantId])->assertSuccessful();

    expect($first->fresh()->path)->toBe("{$this->tenantId}/Rechnungen/beleg.pdf")
        ->and($second->fresh()->path)->toBe("{$this->tenantId}/Rechnungen/beleg-2.pdf");
});

it('materializes an empty folder as a directory', function (): void {
    MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Leer']);

    $this->artisan('media:restructure', ['--tenant' => $this->tenantId])->assertSuccessful();

    expect(Storage::disk('media')->directories((string) $this->tenantId))
        ->toContain("{$this->tenantId}/Leer");
});

it('leaves content of other modules on the same disk untouched', function (): void {
    Storage::disk('media')->put('crm/print-mailings/7/template.pdf', 'PDF');

    $this->artisan('media:restructure', ['--tenant' => $this->tenantId])->assertSuccessful();

    Storage::disk('media')->assertExists('crm/print-mailings/7/template.pdf');
});
