<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Models\NoerdUser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    $this->user = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
    $this->tenantId = (int) $this->user->selected_tenant_id;
    $this->migration = require __DIR__ . '/../../database/migrations/2026_10_01_000001_move_generated_media_directories.php';
});

it('moves the dot directories to the reserved names and rewrites the thumbnail path', function (): void {
    $disk = Storage::disk('media');
    $disk->put("{$this->tenantId}/.thumbnails/thumb_photo.jpg", 'THUMB');
    $disk->put("{$this->tenantId}/.variants/web/1_600.webp", 'VARIANT');
    $disk->put("{$this->tenantId}/photo.jpg", 'ORIGINAL');

    $media = Media::factory()->file($this->tenantId, 'photo.jpg')->create([
        'thumbnail' => "{$this->tenantId}/.thumbnails/thumb_photo.jpg",
    ]);

    $this->migration->up();

    expect($media->fresh()->thumbnail)->toBe("{$this->tenantId}/_thumbnails/thumb_photo.jpg");
    $disk->assertExists("{$this->tenantId}/_thumbnails/thumb_photo.jpg");
    $disk->assertExists("{$this->tenantId}/_variants/web/1_600.webp");
    $disk->assertExists("{$this->tenantId}/photo.jpg");
    expect($disk->directoryExists("{$this->tenantId}/.thumbnails"))->toBeFalse()
        ->and($disk->directoryExists("{$this->tenantId}/.variants"))->toBeFalse();
});

it('can run twice without losing a file', function (): void {
    $disk = Storage::disk('media');
    $disk->put("{$this->tenantId}/.thumbnails/thumb_photo.jpg", 'THUMB');

    $media = Media::factory()->file($this->tenantId, 'photo.jpg')->create([
        'thumbnail' => "{$this->tenantId}/.thumbnails/thumb_photo.jpg",
    ]);

    $this->migration->up();
    $this->migration->up();

    expect($media->fresh()->thumbnail)->toBe("{$this->tenantId}/_thumbnails/thumb_photo.jpg");
    expect($disk->get("{$this->tenantId}/_thumbnails/thumb_photo.jpg"))->toBe('THUMB');
});

it('leaves directories outside the tenant roots untouched', function (): void {
    $disk = Storage::disk('media');
    $disk->put('crm/.thumbnails/keep.jpg', 'KEEP');

    $this->migration->up();

    $disk->assertExists('crm/.thumbnails/keep.jpg');
});
