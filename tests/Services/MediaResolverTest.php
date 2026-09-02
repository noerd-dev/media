<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Noerd\Contracts\MediaResolverContract;
use Noerd\Media\Models\Media;
use Noerd\Media\Services\MediaResolver;
use Noerd\Models\NoerdUser;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');

    $this->user = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($this->user);
    $this->tenantId = $this->user->selected_tenant_id;
});

it('binds the media module behind the core media resolver contract', function (): void {
    expect(app(MediaResolverContract::class))->toBeInstanceOf(MediaResolver::class);
});

it('answers the seam methods a consuming module relies on', function (): void {
    $resolver = app(MediaResolverContract::class);

    expect($resolver->isAvailable())->toBeTrue();
    expect($resolver->pickerComponent())->toBe('media::media-list');
});

it('reports existence and resolves the preview url of a stored media', function (): void {
    config(['media.private' => false]);

    $media = Media::factory()->create([
        'tenant_id' => $this->tenantId,
        'disk' => 'media',
        'path' => $this->tenantId . '/photo.jpg',
        'thumbnail' => $this->tenantId . '/thumbnails/thumb_photo.jpg',
    ]);

    $resolver = app(MediaResolverContract::class);

    expect($resolver->exists($media->id))->toBeTrue();
    expect($resolver->exists($media->id + 9999))->toBeFalse();

    expect($resolver->getPreviewUrl($media->id))->toBe($media->thumbnailUrl());
    expect($resolver->getPreviewUrl($media->id + 9999))->toBeNull();
});

it('resolves the preview url through the authenticated route in private mode', function (): void {
    config(['media.private' => true]);

    $media = Media::factory()->create([
        'tenant_id' => $this->tenantId,
        'disk' => 'media',
        'path' => $this->tenantId . '/photo.jpg',
        'thumbnail' => $this->tenantId . '/thumbnails/thumb_photo.jpg',
    ]);

    expect(app(MediaResolverContract::class)->getPreviewUrl($media->id))
        ->toBe(route('media.thumbnail', $media));
});

it('returns a storage-relative url for a stored media', function (): void {
    $media = Media::factory()->create([
        'tenant_id' => $this->tenantId,
        'disk' => 'media',
        'path' => $this->tenantId . '/photo.jpg',
    ]);

    // Consumers embed the returned value as a site-relative src attribute.
    expect(app(MediaResolverContract::class)->getRelativeUrl($media->id))
        ->toStartWith('/storage/')
        ->toEndWith($media->path);
    expect(app(MediaResolverContract::class)->getRelativeUrl($media->id + 9999))->toBeNull();
});

it('stores an uploaded file and returns its storage-relative url', function (): void {
    $relativeUrl = app(MediaResolverContract::class)
        ->storeUploadedFile(UploadedFile::fake()->image('upload.jpg', 40, 30));

    $media = Media::where('name', 'upload.jpg')->firstOrFail();

    expect($media->tenant_id)->toBe($this->tenantId);
    expect(Storage::disk('media')->exists($media->path))->toBeTrue();
    expect($relativeUrl)->toStartWith('/storage/')->toEndWith($media->path);
});
