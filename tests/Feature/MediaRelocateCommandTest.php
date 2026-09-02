<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

/*
 | The command moves files between the two REAL storage roots, so the whole
 | test runs against a throwaway storage path.
 */
beforeEach(function (): void {
    $this->originalStoragePath = $this->app->storagePath();
    $this->storage = $this->originalStoragePath . '/framework/testing/zz-media-relocate-' . getmypid();

    File::deleteDirectory($this->storage);
    File::ensureDirectoryExists($this->storage);

    $this->app->useStoragePath($this->storage);

    $this->publicRoot = $this->storage . '/app/public/media';
    $this->privateRoot = $this->storage . '/app/media';
});

afterEach(function (): void {
    $this->app->useStoragePath($this->originalStoragePath);
    File::deleteDirectory($this->storage);
});

it('moves the media files from the public into the private root', function (): void {
    File::ensureDirectoryExists($this->publicRoot . '/7/thumbnails');
    File::put($this->publicRoot . '/7/photo.jpg', 'BYTES');
    File::put($this->publicRoot . '/7/thumbnails/thumb_photo.jpg', 'THUMB');

    $exit = Artisan::call('noerd:media-relocate', ['--to' => 'private']);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('Moved 2 file(s)');

    expect(File::get($this->privateRoot . '/7/photo.jpg'))->toBe('BYTES');
    expect(File::get($this->privateRoot . '/7/thumbnails/thumb_photo.jpg'))->toBe('THUMB');
    expect(File::exists($this->publicRoot . '/7/photo.jpg'))->toBeFalse();
});

it('keeps a file that already exists at the destination and reports it as skipped', function (): void {
    File::ensureDirectoryExists($this->publicRoot . '/7');
    File::put($this->publicRoot . '/7/photo.jpg', 'SOURCE');

    File::ensureDirectoryExists($this->privateRoot . '/7');
    File::put($this->privateRoot . '/7/photo.jpg', 'DESTINATION');

    expect(Artisan::call('noerd:media-relocate', ['--to' => 'private']))->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('Moved 0 file(s)')
        ->toContain('Skipped 1 file(s)');

    expect(File::get($this->privateRoot . '/7/photo.jpg'))->toBe('DESTINATION');
    expect(File::get($this->publicRoot . '/7/photo.jpg'))->toBe('SOURCE');
});

it('moves the media files back into the public root', function (): void {
    File::ensureDirectoryExists($this->privateRoot . '/7');
    File::put($this->privateRoot . '/7/photo.jpg', 'BYTES');

    expect(Artisan::call('noerd:media-relocate', ['--to' => 'public']))->toBe(0);

    expect(File::get($this->publicRoot . '/7/photo.jpg'))->toBe('BYTES');
    expect(File::exists($this->privateRoot . '/7/photo.jpg'))->toBeFalse();
});

it('rejects an unknown target location', function (): void {
    expect(Artisan::call('noerd:media-relocate', ['--to' => 'elsewhere']))->toBe(1);
});
