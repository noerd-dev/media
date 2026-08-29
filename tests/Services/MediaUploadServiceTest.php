<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media as MediaModel;
use Noerd\Media\Services\MediaUploadService;
use Noerd\Models\NoerdUser;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
});

it('stores media from uploaded file and creates thumbnail', function (): void {
    $user = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($user);

    $service = app(MediaUploadService::class);
    $fakeImage = UploadedFile::fake()->image('test.jpg', 800, 600);

    $media = $service->storeFromUploadedFile($fakeImage);

    expect($media->tenant_id)->toBe($user->selected_tenant_id)
        ->and($media->disk)->toBe('media')
        ->and($media->name)->toBe('test.jpg')
        ->and($media->extension)->toBe('jpg')
        ->and($media->path)->not->toBe('')
        ->and($media->thumbnail)->not->toBeNull();

    expect(Storage::disk('media')->exists($media->path))->toBeTrue();
    expect(Storage::disk('media')->exists($media->thumbnail))->toBeTrue();
});

it('stores media from array payload (dropzone style)', function (): void {
    $user = NoerdUser::factory()->withExampleTenant()->create();
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
        ->and($media->disk)->toBe('media')
        ->and($media->name)->toBe('photo.jpg')
        ->and($media->extension)->toBe('jpg')
        ->and($media->path)->not->toBe('')
        ->and($media->thumbnail)->not->toBeNull();

    expect(Storage::disk('media')->exists($media->path))->toBeTrue();
    expect(Storage::disk('media')->exists($media->thumbnail))->toBeTrue();
});

it('replaces umlauts and special characters in filenames', function (string $input, string $expected, string $entrypoint): void {
    $user = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($user);

    $service = app(MediaUploadService::class);

    if ($entrypoint === 'uploadedFile') {
        $media = $service->storeFromUploadedFile(UploadedFile::fake()->image($input, 800, 600));
    } else {
        $fakeImage = UploadedFile::fake()->image('source.jpg', 800, 600);
        $media = $service->storeFromArray([
            'name' => $input,
            'extension' => 'jpg',
            'size' => $fakeImage->getSize(),
            'path' => $fakeImage->getRealPath(),
        ]);
    }

    expect($media->name)->toBe($expected)
        ->and($media->path)->toContain($expected);
})->with([
    'umlauts via uploaded file' => ['täst_öffnung_über.jpg', 'taest_oeffnung_ueber.jpg', 'uploadedFile'],
    'umlauts via array payload' => ['groß_Übung.jpg', 'gross_Uebung.jpg', 'array'],
    'special characters via uploaded file' => ['täst_œuvre_cæsar.jpg', 'taest_oeuvre_caesar.jpg', 'uploadedFile'],
]);
