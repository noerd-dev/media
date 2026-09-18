<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Tests\Support\CreatesImageFixtures;
use Noerd\Models\NoerdUser;
use Noerd\Models\Tenant;

uses(Tests\TestCase::class, RefreshDatabase::class, CreatesImageFixtures::class);

beforeEach(function (): void {
    Storage::fake('media');
    config(['media.variants' => ['web' => 600]]);

    $this->tenantId = Tenant::factory()->create()->id;
});

it('delivers the scaled variant to an anonymous visitor holding a signed url', function (): void {
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);

    $response = $this->get($media->imageUrl());

    $response->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('max-age=31536000')->toContain('immutable')
        ->and(getimagesizefromstring($response->streamedContent())[0])->toBe(600);
});

it('builds a relative, stable url that validates on any host', function (): void {
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);

    $url = $media->imageUrl();

    expect($url)->toStartWith("/media/image/{$media->id}/web?")
        ->and($media->fresh()->imageUrl())->toBe($url);

    $this->get('https://shop.example.test' . $url)->assertOk();
});

it('rejects a missing or tampered signature', function (): void {
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);
    $other = $this->zzStoredImage($this->tenantId, 'receipt.jpg', 1600, 800);

    $this->get("/media/image/{$media->id}/web")->assertForbidden();
    $this->get(str_replace("/{$media->id}/", "/{$other->id}/", $media->imageUrl()))->assertForbidden();
});

it('answers 404 for a variant that is not configured', function (): void {
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);
    $url = $media->imageUrl();

    config(['media.variants' => ['other' => 600]]);

    $this->get($url)->assertNotFound();
});

it('delivers the image to a backend user of another tenant', function (): void {
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);
    $url = $media->imageUrl();

    $this->actingAs(NoerdUser::factory()->withExampleTenant()->create());

    $this->get($url)->assertOk();
});

it('delivers in private mode, where the storage url is dead', function (): void {
    config(['media.private' => true]);
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);

    $this->get($media->imageUrl())->assertOk();
});

it('falls back to the original when no variant can be generated', function (): void {
    config(['media.variant_max_pixels' => 1000]);
    $media = $this->zzStoredImage($this->tenantId, 'photo.jpg', 1600, 800);

    $response = $this->get($media->imageUrl());

    $response->assertOk();

    expect(getimagesizefromstring($response->streamedContent())[0])->toBe(1600);
});

it('answers with the original url for a file that cannot be scaled', function (): void {
    $svg = Media::factory()->file($this->tenantId, 'icon.svg')->create();

    expect($svg->imageUrl())
        ->toBe(mb_strstr(Storage::disk('media')->url($svg->path), '/storage'))
        ->not->toContain('signature');
});
