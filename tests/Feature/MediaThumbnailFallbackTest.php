<?php

use Noerd\Media\Models\Media;

uses(Tests\TestCase::class);

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
