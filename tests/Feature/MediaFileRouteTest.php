<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Models\NoerdUser;
use Noerd\Models\Tenant;

uses(Tests\TestCase::class, RefreshDatabase::class);

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

it('redirects guests to login when requesting a private file', function (): void {
    config(['media.private' => true]);

    $tenant = Tenant::factory()->create();
    $media = Media::factory()->create([
        'tenant_id' => $tenant->id,
        'disk' => 'media',
        'path' => "{$tenant->id}/photo.jpg",
    ]);

    $this->get(route('media.file', $media))->assertRedirect(route('noerd.login'));
});

it('streams the file to a same-tenant user', function (): void {
    config(['media.private' => true]);
    Storage::fake('media');

    $user = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($user);
    $tenantId = $user->selected_tenant_id;

    $media = Media::factory()->create([
        'tenant_id' => $tenantId,
        'disk' => 'media',
        'path' => "{$tenantId}/photo.jpg",
    ]);
    Storage::disk('media')->put("{$tenantId}/photo.jpg", 'BYTES');

    $response = $this->get(route('media.file', $media));

    $response->assertOk();
    expect($response->streamedContent())->toBe('BYTES');
});

it('serves the thumbnail, falling back to the original when none exists', function (): void {
    config(['media.private' => true]);
    Storage::fake('media');

    $user = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($user);
    $tenantId = $user->selected_tenant_id;

    $media = Media::factory()->create([
        'tenant_id' => $tenantId,
        'disk' => 'media',
        'path' => "{$tenantId}/photo.jpg",
        'thumbnail' => null,
    ]);
    Storage::disk('media')->put("{$tenantId}/photo.jpg", 'ORIGINAL');

    $response = $this->get(route('media.thumbnail', $media));

    $response->assertOk();
    expect($response->streamedContent())->toBe('ORIGINAL');
});

it('returns 404 for a user from another tenant', function (): void {
    config(['media.private' => true]);
    Storage::fake('media');

    $owner = NoerdUser::factory()->withExampleTenant()->create();
    $ownerTenantId = $owner->selected_tenant_id;

    $media = Media::factory()->create([
        'tenant_id' => $ownerTenantId,
        'disk' => 'media',
        'path' => "{$ownerTenantId}/photo.jpg",
    ]);
    Storage::disk('media')->put("{$ownerTenantId}/photo.jpg", 'BYTES');

    // Creating this user switches the selected tenant in the session.
    $other = NoerdUser::factory()->withExampleTenant()->create();
    $this->actingAs($other);

    $this->get(route('media.file', $media))->assertNotFound();
});
