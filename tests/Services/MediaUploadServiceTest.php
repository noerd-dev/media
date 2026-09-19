<?php

declare(strict_types=1);

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
        '_original' => $fakeImage,
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

it('keeps readable file names and strips only what a path must not contain', function (string $input, string $expected, string $entrypoint): void {
    $user = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($user);

    $service = app(MediaUploadService::class);

    if ($entrypoint === 'uploadedFile') {
        $media = $service->storeFromUploadedFile(UploadedFile::fake()->image($input, 800, 600));
    } else {
        // The dropzone array carries the upload itself; its plain scalars are
        // client-controlled and therefore never read by the service.
        $fakeImage = UploadedFile::fake()->image($input, 800, 600);
        $media = $service->storeFromArray([
            'name' => $input,
            'extension' => 'jpg',
            'size' => $fakeImage->getSize(),
            '_original' => $fakeImage,
        ]);
    }

    expect($media->name)->toBe($expected)
        ->and($media->path)->toContain($expected);
})->with([
    // The disk mirrors the library and people read and fill it, so umlauts stay
    // — a transliterated name next to a hand-made file would be two names for
    // one thing. Only what breaks a path is removed.
    'umlauts via uploaded file' => ['täst_öffnung_über.jpg', 'täst_öffnung_über.jpg', 'uploadedFile'],
    'umlauts via array payload' => ['groß_Übung.jpg', 'groß_Übung.jpg', 'array'],
    // The array entrypoint reads the name off the upload, and an upload's
    // client name is already reduced to its basename — so a traversal attempt
    // loses its directories before the sanitizer ever sees it.
    'directory traversal via array payload' => ['../../etc/passwd.jpg', 'passwd.jpg', 'array'],
    'wildcards via array payload' => ['re*chnung?.jpg', 'rechnung.jpg', 'array'],
]);

// The dropzone array lives in a public Livewire property, so every plain value
// in it is attacker-controlled. Reading a file system path out of it used to
// turn any authenticated user into an arbitrary file reader (.env, keys). Only
// the signed upload behind `_original` is trusted.
it('refuses a fabricated payload that names a file on the server', function (array $payload): void {
    $user = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($user);

    $secret = tempnam(sys_get_temp_dir(), 'zzsecret');
    file_put_contents($secret, 'APP_KEY=base64:do-not-leak');

    $payload = array_map(
        fn ($value): mixed => $value === '__SECRET__' ? $secret : $value,
        $payload,
    );

    $before = MediaModel::count();

    try {
        expect(fn () => app(MediaUploadService::class)->storeFromArray($payload))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        @unlink($secret);
    }

    expect(MediaModel::count())->toBe($before);
    expect(Storage::disk('media')->allFiles())->toBe([]);
})->with([
    'legacy path key' => [['name' => 'x.txt', 'extension' => 'txt', 'size' => 10, 'path' => '__SECRET__']],
    'forged original as path' => [['name' => 'x.txt', 'extension' => 'txt', 'size' => 10, '_original' => '__SECRET__']],
    'no upload at all' => [['name' => 'x.txt', 'extension' => 'txt', 'size' => 10]],
]);

it('does not write anything when the media list is fed a fabricated file entry', function (): void {
    $user = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
    $this->actingAs($user);

    $secret = tempnam(sys_get_temp_dir(), 'zzsecret');
    file_put_contents($secret, 'APP_KEY=base64:do-not-leak');

    $before = MediaModel::count();

    Livewire\Livewire::test('media::media-list')
        ->set('files', [[
            'name' => 'passwd.txt',
            'extension' => 'txt',
            'size' => 10,
            'path' => $secret,
        ]])
        ->call('store');

    @unlink($secret);

    expect(MediaModel::count())->toBe($before);
    expect(Storage::disk('media')->allFiles())->toBe([]);
});
