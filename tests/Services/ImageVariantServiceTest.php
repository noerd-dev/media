<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Services\ImageVariantService;
use Noerd\Media\Tests\Support\CreatesImageFixtures;
use Noerd\Models\Tenant;

uses(Tests\TestCase::class, RefreshDatabase::class, CreatesImageFixtures::class);

beforeEach(function (): void {
    Storage::fake('media');
    config(['media.variants' => ['web' => 600, 'small' => 200]]);

    $this->tenantId = Tenant::factory()->create()->id;
    $this->variants = app(ImageVariantService::class);
});

it('scales an oversized image down to the configured width', function (): void {
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);

    $path = $this->variants->pathFor($media, 'web');

    expect($path)->toStartWith("{$this->tenantId}/.variants/web/{$media->id}_600.");

    [$width, $height] = getimagesizefromstring(Storage::disk('media')->get($path));

    expect($width)->toBe(600)->and($height)->toBe(300);
});

it('encodes the variant as WebP when GD supports it', function (): void {
    if (! function_exists('imagewebp')) {
        $this->markTestSkipped('This GD build has no WebP support.');
    }

    $media = $this->zzStoredImage($this->tenantId, 'logo.png', 900, 900);

    $path = $this->variants->pathFor($media, 'web');

    expect($path)->toEndWith('.webp')
        ->and(getimagesizefromstring(Storage::disk('media')->get($path))['mime'])->toBe('image/webp');
});

it('never upscales a smaller image', function (): void {
    $media = $this->zzStoredImage($this->tenantId, 'small.jpg', 300, 150);

    [$width] = getimagesizefromstring(Storage::disk('media')->get($this->variants->pathFor($media, 'web')));

    expect($width)->toBe(300);
});

it('keeps one cached file per variant and reuses it', function (): void {
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);

    $web = $this->variants->pathFor($media, 'web');
    $small = $this->variants->pathFor($media, 'small');

    Storage::disk('media')->put($web, 'CACHED');

    expect($small)->not->toBe($web)
        ->and($this->variants->pathFor($media, 'web'))->toBe($web)
        ->and(Storage::disk('media')->get($web))->toBe('CACHED');
});

it('leaves the original untouched', function (): void {
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);
    $original = Storage::disk('media')->get($media->path);

    $this->variants->pathFor($media, 'web');

    expect(Storage::disk('media')->get($media->path))->toBe($original);
});

it('offers no variant for files GD cannot scale or names that are not configured', function (): void {
    $svg = Media::factory()->file($this->tenantId, 'icon.svg')->create();
    $pdf = Media::factory()->file($this->tenantId, 'doc.pdf')->create();
    $jpg = $this->zzStoredImage($this->tenantId, 'photo.jpg', 800, 400);

    expect($this->variants->supports($svg, 'web'))->toBeFalse()
        ->and($this->variants->supports($pdf, 'web'))->toBeFalse()
        ->and($this->variants->supports($jpg, 'unknown'))->toBeFalse()
        ->and($this->variants->pathFor($svg, 'web'))->toBeNull()
        ->and($this->variants->pathFor($jpg, 'unknown'))->toBeNull();
});

it('answers null instead of decoding an image above the pixel budget', function (): void {
    config(['media.variant_max_pixels' => 1000]);
    $media = $this->zzStoredImage($this->tenantId, 'huge.jpg', 1600, 800);

    expect($this->variants->pathFor($media, 'web'))->toBeNull()
        ->and(Storage::disk('media')->allFiles("{$this->tenantId}/.variants"))->toBe([]);
});

it('answers null for a missing or unreadable source', function (): void {
    $missing = Media::factory()->file($this->tenantId, 'gone.jpg')->create();
    $broken = Media::factory()->file($this->tenantId, 'broken.jpg')->create();
    Storage::disk('media')->put($broken->path, 'not an image');

    expect($this->variants->pathFor($missing, 'web'))->toBeNull()
        ->and($this->variants->pathFor($broken, 'web'))->toBeNull();
});

it('forgets every cached variant of a file and nobody else\'s', function (): void {
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);
    $other = $this->zzStoredImage($this->tenantId, 'other.jpg', 1600, 800);

    $web = $this->variants->pathFor($media, 'web');
    $small = $this->variants->pathFor($media, 'small');
    $kept = $this->variants->pathFor($other, 'web');

    $this->variants->forget($media);

    Storage::disk('media')->assertMissing($web);
    Storage::disk('media')->assertMissing($small);
    Storage::disk('media')->assertExists($kept);
});

it('clears the variant cache of a tenant on request', function (): void {
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);
    $path = $this->variants->pathFor($media, 'web');

    $this->artisan('media:clear-variants')->assertSuccessful();

    Storage::disk('media')->assertMissing($path);
    Storage::disk('media')->assertExists($media->path);
});
