<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Models\Tenant;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
});

function zzStoredImage(int $tenantId, string $name): Media
{
    $media = Media::factory()->file($tenantId, $name)->create();
    Storage::disk('media')->put($media->path, UploadedFile::fake()->image($name, 800, 600)->getContent());

    return $media;
}

it('regenerates the thumbnails of every tenant without a signed-in user', function (): void {
    $first = zzStoredImage(Tenant::factory()->create()->id, 'eins.png');
    $second = zzStoredImage(Tenant::factory()->create()->id, 'zwei.png');

    $this->artisan('media:regenerate-thumbnails', ['--all' => true])->assertSuccessful();

    foreach ([$first, $second] as $media) {
        $thumbnail = $media->fresh()->thumbnail;

        expect($thumbnail)->not->toBeNull();
        Storage::disk('media')->assertExists($thumbnail);
    }
});

it('regenerates the thumbnail of one file by id without a signed-in user', function (): void {
    $media = zzStoredImage(Tenant::factory()->create()->id, 'einzeln.png');

    $this->artisan('media:regenerate-thumbnails', ['--id' => $media->id])->assertSuccessful();

    expect($media->fresh()->thumbnail)->not->toBeNull();
});
