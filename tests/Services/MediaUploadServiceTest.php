<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Noerd\Noerd\Models\User;
use Nywerk\Media\Models\Media as MediaModel;
use Nywerk\Media\Services\MediaUploadService;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('images');
});

it('stores media from uploaded file and creates thumbnail', function (): void {
    $user = User::factory()->withContentModule()->create();
    $this->actingAs($user);

    $service = app(MediaUploadService::class);
    $fakeImage = UploadedFile::fake()->image('test.jpg', 800, 600);

    $media = $service->storeFromUploadedFile($fakeImage);

    expect($media->tenant_id)->toBe($user->selected_tenant_id)
        ->and($media->disk)->toBe('images')
        ->and($media->name)->toBe('test.jpg')
        ->and($media->extension)->toBe('jpg')
        ->and($media->path)->not->toBe('')
        ->and($media->thumbnail)->not->toBeNull();

    expect(Storage::disk('images')->exists($media->path))->toBeTrue();
    expect(Storage::disk('images')->exists($media->thumbnail))->toBeTrue();
});

it('stores media from array payload (dropzone style)', function (): void {
    $user = User::factory()->withContentModule()->create();
    $this->actingAs($user);

    $service = app(MediaUploadService::class);
    $fakeImage = UploadedFile::fake()->image('photo.jpg', 1200, 800);

    $payload = [
        'name' => 'photo.jpg',
        'extension' => 'jpg',
        'size' => $fakeImage->getSize(),
        'path' => $fakeImage->getRealPath(),
    ];

    $before = MediaModel::count();
    $media = $service->storeFromArray($payload);

    expect(MediaModel::count())->toBe($before + 1);
    expect($media->tenant_id)->toBe($user->selected_tenant_id)
        ->and($media->disk)->toBe('images')
        ->and($media->name)->toBe('photo.jpg')
        ->and($media->extension)->toBe('jpg')
        ->and($media->path)->not->toBe('')
        ->and($media->thumbnail)->not->toBeNull();

    expect(Storage::disk('images')->exists($media->path))->toBeTrue();
    expect(Storage::disk('images')->exists($media->thumbnail))->toBeTrue();
});


