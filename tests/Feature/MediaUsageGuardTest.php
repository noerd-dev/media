<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Noerd\Media\Exceptions\MediaInUseException;
use Noerd\Media\Models\Media;
use Noerd\Media\Services\MediaUsageRegistry;
use Noerd\Models\NoerdUser;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    $this->user = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
    $this->actingAs($this->user);
});

/**
 * A file another module still needs. Registered ad hoc so the media module
 * stays testable without any of the modules that actually use it.
 */
function zzGuardMedia(int $tenantId, string $name = 'inuse.jpg'): Media
{
    $media = Media::factory()->file($tenantId, $name)->create();
    Storage::disk('media')->put($media->path, 'x');

    return $media;
}

it('refuses to delete a file another module still needs', function (): void {
    $media = zzGuardMedia($this->user->selected_tenant_id);

    app(MediaUsageRegistry::class)->register(
        'zz',
        fn(Media $candidate): ?string => $candidate->id === $media->id ? 'Used by 2 receipts' : null,
    );

    Livewire::test('media::media-list')
        ->call('deleteMedia', $media->id)
        ->assertSet('deleteError', 'Used by 2 receipts');

    // The regression this test exists for: the library used to delete the file
    // BEFORE the row, so a refused deletion left a record pointing at nothing.
    expect(Media::find($media->id))->not->toBeNull()
        ->and(Storage::disk('media')->exists($media->path))->toBeTrue();
});

it('still deletes a file nobody claims', function (): void {
    $free = zzGuardMedia($this->user->selected_tenant_id, 'free.jpg');
    $used = zzGuardMedia($this->user->selected_tenant_id, 'used.jpg');

    app(MediaUsageRegistry::class)->register(
        'zz',
        fn(Media $candidate): ?string => $candidate->id === $used->id ? 'Used by a receipt' : null,
    );

    Livewire::test('media::media-list')
        ->call('deleteMedia', $free->id)
        ->assertSet('deleteError', null);

    expect(Media::find($free->id))->toBeNull()
        ->and(Storage::disk('media')->exists($free->path))->toBeFalse();
});

it('deletes the free files of a selection and keeps the claimed one', function (): void {
    $free = zzGuardMedia($this->user->selected_tenant_id, 'free.jpg');
    $used = zzGuardMedia($this->user->selected_tenant_id, 'used.jpg');

    app(MediaUsageRegistry::class)->register(
        'zz',
        fn(Media $candidate): ?string => $candidate->id === $used->id ? 'Used by a receipt' : null,
    );

    Livewire::test('media::media-list')
        ->set('selectedMediaIds', [$free->id, $used->id])
        ->call('deleteSelectedMedia')
        ->assertSet('deleteError', 'Used by a receipt')
        // The claimed one stays selected, so the reason is about something visible.
        ->assertSet('selectedMediaIds', [$used->id]);

    expect(Media::find($free->id))->toBeNull()
        ->and(Media::find($used->id))->not->toBeNull()
        ->and(Storage::disk('media')->exists($used->path))->toBeTrue();
});

it('lets a module that cannot answer fall through instead of blocking the file', function (): void {
    $media = zzGuardMedia($this->user->selected_tenant_id);

    app(MediaUsageRegistry::class)->register('zz-broken', function (Media $candidate): ?string {
        throw new RuntimeException('module is broken');
    });

    Livewire::test('media::media-list')->call('deleteMedia', $media->id);

    expect(Media::find($media->id))->toBeNull();
});

it('throws on the model itself, so every deletion path is covered', function (): void {
    $media = zzGuardMedia($this->user->selected_tenant_id);

    app(MediaUsageRegistry::class)->register('zz', fn(Media $candidate): ?string => 'Used by a receipt');

    expect(fn(): mixed => $media->delete())->toThrow(MediaInUseException::class);
});
