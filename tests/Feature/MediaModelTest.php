<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Noerd\Media\Models\Media;
use Noerd\Models\Tenant;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('reports a renderable thumbnail once one has been generated', function (): void {
    $media = Media::factory()->make([
        'extension' => 'pdf',
        'thumbnail' => '1/thumbnails/pdf_abc.jpg',
    ]);

    expect($media->hasRenderableThumbnail())->toBeTrue();
});

it('falls back to the original file for images without a generated thumbnail', function (): void {
    $media = Media::factory()->make([
        'name' => 'photo.jpg',
        'extension' => 'jpg',
        'path' => '1/photo.jpg',
        'thumbnail' => null,
    ]);

    expect($media->hasRenderableThumbnail())->toBeTrue();
});

it('reports no renderable thumbnail for a non-image without a generated thumbnail', function (): void {
    $media = Media::factory()->make([
        'extension' => 'pdf',
        'thumbnail' => null,
    ]);

    expect($media->hasRenderableThumbnail())->toBeFalse();
});

it('normalizes the extension from the column and from the path', function (): void {
    expect(Media::factory()->make(['extension' => 'PDF'])->normalizedExtension())->toBe('pdf');

    expect(Media::factory()->make(['extension' => null, 'path' => '1/file.WEBP'])->normalizedExtension())
        ->toBe('webp');
});

it('returns a direct /storage url in public mode', function (): void {
    config(['media.private' => false]);

    $tenant = Tenant::factory()->create();
    $media = Media::factory()->create([
        'tenant_id' => $tenant->id,
        'disk' => 'media',
        'path' => "{$tenant->id}/photo.jpg",
    ]);

    expect($media->url())->toContain("/storage/media/{$tenant->id}/photo.jpg");
});

it('returns the authenticated route in private mode', function (): void {
    config(['media.private' => true]);

    $tenant = Tenant::factory()->create();
    $media = Media::factory()->create([
        'tenant_id' => $tenant->id,
        'disk' => 'media',
        'path' => "{$tenant->id}/photo.jpg",
        'thumbnail' => "{$tenant->id}/thumbnails/thumb_photo.jpg",
    ]);

    expect($media->url())->toBe(route('media.file', $media))
        ->and($media->thumbnailUrl())->toBe(route('media.thumbnail', $media));
});
