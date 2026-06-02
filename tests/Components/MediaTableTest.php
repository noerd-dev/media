<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaTag;
use Noerd\Models\NoerdUser;
use Noerd\Models\Tenant;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    $this->user = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
    $this->actingAs($this->user);
});

it('stores uploaded files via service when calling store()', function (): void {
    // Create a real temporary file to satisfy file_get_contents in service
    $tmpFile = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($tmpFile, 'fake image content');

    // Use a non-image extension to bypass preview generation in tests
    $filePayload = [
        'name' => 'foo.jpg',
        'extension' => 'txt',
        'size' => 1234,
        'path' => $tmpFile,
    ];

    $before = Media::count();

    Livewire::test('media::media-list')
        ->set('files', [$filePayload])
        ->call('store');

    expect(Media::count())->toBe($before + 1);
    $media = Media::latest('id')->first();
    expect($media->tenant_id)->toBe($this->user->selected_tenant_id)
        ->and($media->disk)->toBe('media')
        ->and(Storage::disk('media')->exists($media->path))->toBeTrue();
});

it('can add, attach and detach tags for selected media', function (): void {
    $media = Media::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'type' => 'image',
        'name' => 'bar.jpg',
        'extension' => 'jpg',
        'path' => $this->user->selected_tenant_id . '/bar.jpg',
        'disk' => 'media',
        'size' => 99,
    ]);

    $component = Livewire::test('media::media-list')
        ->call('selectMedia', $media->id)
        ->call('addOrAttachTag', 'TestTag');

    $tag = MediaTag::where('tenant_id', $this->user->selected_tenant_id)->where('name', 'TestTag')->first();
    expect($tag)->not->toBeNull();
    expect($media->fresh()->tags()->pluck('name'))->toContain('TestTag');

    // Attach existing different tag
    $existing = MediaTag::firstOrCreate([
        'tenant_id' => $this->user->selected_tenant_id,
        'name' => 'AnotherTag',
    ]);

    $component->call('attachTag', $existing->id);
    expect($media->fresh()->tags()->pluck('name'))->toContain('AnotherTag');

    // Detach
    $component->call('detachTag', $existing->id);
    expect($media->fresh()->tags()->pluck('name'))->not->toContain('AnotherTag');
});

it('filters media by multiple tags (AND)', function (): void {
    // Create tags
    $tagA = MediaTag::create(['tenant_id' => $this->user->selected_tenant_id, 'name' => 'A']);
    $tagB = MediaTag::create(['tenant_id' => $this->user->selected_tenant_id, 'name' => 'B']);

    // Media 1: A only
    $m1 = Media::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'type' => 'image', 'name' => 'm1.jpg', 'extension' => 'jpg', 'path' => $this->user->selected_tenant_id . '/m1.jpg', 'disk' => 'media', 'size' => 1,
    ]);
    $m1->tags()->sync([$tagA->id]);

    // Media 2: B only
    $m2 = Media::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'type' => 'image', 'name' => 'm2.jpg', 'extension' => 'jpg', 'path' => $this->user->selected_tenant_id . '/m2.jpg', 'disk' => 'media', 'size' => 1,
    ]);
    $m2->tags()->sync([$tagB->id]);

    // Media 3: A and B
    $m3 = Media::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'type' => 'image', 'name' => 'm3.jpg', 'extension' => 'jpg', 'path' => $this->user->selected_tenant_id . '/m3.jpg', 'disk' => 'media', 'size' => 1,
    ]);
    $m3->tags()->sync([$tagA->id, $tagB->id]);

    $component = Livewire::test('media::media-list')
        ->set('filterTagIds', [$tagA->id, $tagB->id]);

    $rows = $component->viewData('listConfig')['rows'];
    $ids = collect($rows)->pluck('id');

    expect($ids)->toContain($m3->id);
    expect($ids)->not->toContain($m1->id);
    expect($ids)->not->toContain($m2->id);
});

it('deletes media and removes file from disk', function (): void {
    $path = $this->user->selected_tenant_id . '/todelete.jpg';
    Storage::disk('media')->put($path, 'x');

    $media = Media::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'type' => 'image', 'name' => 'todelete.jpg', 'extension' => 'jpg', 'path' => $path, 'disk' => 'media', 'size' => 1,
    ]);

    Livewire::test('media::media-list')->call('deleteMedia', $media->id);

    expect(Media::find($media->id))->toBeNull();
    expect(Storage::disk('media')->exists($path))->toBeFalse();
});

it('toggles bulk select mode and resets selection on exit', function (): void {
    Livewire::test('media::media-list')
        ->assertSet('bulkSelectMode', false)
        ->assertSet('selectedMediaIds', [])
        ->call('enterBulkSelectMode')
        ->assertSet('bulkSelectMode', true)
        ->assertSet('selectedMediaIds', [])
        ->call('toggleMediaSelection', 42)
        ->assertSet('selectedMediaIds', [42])
        ->call('toggleMediaSelection', 99)
        ->assertSet('selectedMediaIds', [42, 99])
        ->call('toggleMediaSelection', 42)
        ->assertSet('selectedMediaIds', [99])
        ->call('exitBulkSelectMode')
        ->assertSet('bulkSelectMode', false)
        ->assertSet('selectedMediaIds', []);
});

it('deletes multiple selected media items and removes their files', function (): void {
    $tenantId = $this->user->selected_tenant_id;

    $items = collect(['a.jpg', 'b.jpg', 'c.jpg'])->map(function (string $name) use ($tenantId): Media {
        $path = $tenantId . '/' . $name;
        Storage::disk('media')->put($path, 'x');

        return Media::create([
            'tenant_id' => $tenantId,
            'type' => 'image',
            'name' => $name,
            'extension' => 'jpg',
            'path' => $path,
            'disk' => 'media',
            'size' => 1,
        ]);
    });

    $toDelete = [$items[0]->id, $items[2]->id];

    Livewire::test('media::media-list')
        ->call('enterBulkSelectMode')
        ->set('selectedMediaIds', $toDelete)
        ->call('deleteSelectedMedia')
        ->assertSet('bulkSelectMode', false)
        ->assertSet('selectedMediaIds', []);

    expect(Media::find($items[0]->id))->toBeNull();
    expect(Media::find($items[1]->id))->not->toBeNull();
    expect(Media::find($items[2]->id))->toBeNull();
    expect(Storage::disk('media')->exists($items[0]->path))->toBeFalse();
    expect(Storage::disk('media')->exists($items[1]->path))->toBeTrue();
    expect(Storage::disk('media')->exists($items[2]->path))->toBeFalse();
});

it('clears the detail panel when bulk-deleting includes the currently selected media', function (): void {
    // Regression: $this->selected is a Livewire lazy proxy for an Eloquent
    // model. If we delete the underlying record and a follow-up Livewire
    // round-trip touches $this->selected, the proxy's hydration closure
    // calls firstOrFail() on the missing record and throws → HTTP 404.
    $tenantId = $this->user->selected_tenant_id;

    $items = collect(['a.jpg', 'b.jpg', 'c.jpg'])->map(function (string $name) use ($tenantId): Media {
        $path = $tenantId . '/' . $name;
        Storage::disk('media')->put($path, 'x');

        return Media::create([
            'tenant_id' => $tenantId,
            'type' => 'image',
            'name' => $name,
            'extension' => 'jpg',
            'path' => $path,
            'disk' => 'media',
            'size' => 1,
        ]);
    });

    $component = Livewire::test('media::media-list')
        ->call('selectMedia', $items[0]->id)
        ->call('enterBulkSelectMode')
        ->call('toggleMediaSelection', $items[0]->id)
        ->call('toggleMediaSelection', $items[1]->id)
        ->call('deleteSelectedMedia')
        ->assertSet('selected', null);

    // Follow-up round-trip must not 404 (would happen if Livewire's lazy
    // proxy still pointed at a deleted record).
    $component->call('loadMore');

    expect(Media::find($items[0]->id))->toBeNull();
    expect(Media::find($items[1]->id))->toBeNull();
    expect(Media::find($items[2]->id))->not->toBeNull();
});

it('searches media by name', function (): void {
    $tenantId = $this->user->selected_tenant_id;

    $match = Media::create([
        'tenant_id' => $tenantId, 'type' => 'image', 'name' => 'invoice-march.pdf',
        'extension' => 'pdf', 'path' => $tenantId . '/a.pdf', 'disk' => 'media', 'size' => 1,
    ]);
    $unrelated = Media::create([
        'tenant_id' => $tenantId, 'type' => 'image', 'name' => 'photo.jpg',
        'extension' => 'jpg', 'path' => $tenantId . '/c.jpg', 'disk' => 'media', 'size' => 1,
    ]);

    $component = Livewire::test('media::media-list')->set('search', 'invoice');
    $ids = collect($component->viewData('listConfig')['rows'])->pluck('id');

    expect($ids)->toContain($match->id);
    expect($ids)->not->toContain($unrelated->id);
});

it('filters media by extension via the listFilters picklist', function (): void {
    $tenantId = $this->user->selected_tenant_id;

    $jpg = Media::create([
        'tenant_id' => $tenantId, 'type' => 'image', 'name' => 'a.jpg',
        'extension' => 'jpg', 'path' => $tenantId . '/a.jpg', 'disk' => 'media', 'size' => 1,
    ]);
    $pdf = Media::create([
        'tenant_id' => $tenantId, 'type' => 'image', 'name' => 'b.pdf',
        'extension' => 'pdf', 'path' => $tenantId . '/b.pdf', 'disk' => 'media', 'size' => 1,
    ]);

    $component = Livewire::test('media::media-list')
        ->set('listFilters.extension', 'pdf');

    $ids = collect($component->viewData('listConfig')['rows'])->pluck('id');

    expect($ids)->toContain($pdf->id);
    expect($ids)->not->toContain($jpg->id);
});

it('exposes an extension filter with the distinct extensions of the current tenant', function (): void {
    $tenantId = $this->user->selected_tenant_id;

    foreach (['jpg', 'pdf', 'jpg', 'png'] as $i => $ext) {
        Media::create([
            'tenant_id' => $tenantId, 'type' => 'image', 'name' => "f{$i}.{$ext}",
            'extension' => $ext, 'path' => $tenantId . "/f{$i}.{$ext}", 'disk' => 'media', 'size' => 1,
        ]);
    }

    $filters = Livewire::test('media::media-list')->instance()->tableFilters;
    $extensionFilter = collect($filters)->firstWhere('column', 'extension');

    expect($extensionFilter)->not->toBeNull();
    // PHP casts a null array key to '' — that's the "All types" placeholder
    // option used by Picklist filters (mirrors BankAccountFilterTrait).
    expect(array_keys($extensionFilter['options']))->toBe(['', 'jpg', 'pdf', 'png']);
});

it('omits the extension filter entirely when no media exists', function (): void {
    $filters = Livewire::test('media::media-list')->instance()->tableFilters;

    expect(collect($filters)->firstWhere('column', 'extension'))->toBeNull();
});

it('clears search, tag filters and listFilters via clearAllFilters', function (): void {
    $component = Livewire::test('media::media-list')
        ->set('search', 'foo')
        ->set('filterTagIds', [1, 2])
        ->set('listFilters.extension', 'pdf')
        ->call('clearAllFilters');

    $component->assertSet('search', '')
        ->assertSet('filterTagIds', [])
        ->assertSet('listFilters', []);
});

it('does not delete media from other tenants even if id is in selection', function (): void {
    $otherTenant = Tenant::factory()->create();

    $ownPath = $this->user->selected_tenant_id . '/own.jpg';
    Storage::disk('media')->put($ownPath, 'x');
    $own = Media::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'type' => 'image', 'name' => 'own.jpg', 'extension' => 'jpg', 'path' => $ownPath, 'disk' => 'media', 'size' => 1,
    ]);

    $foreignPath = $otherTenant->id . '/foreign.jpg';
    Storage::disk('media')->put($foreignPath, 'x');
    $foreign = Media::create([
        'tenant_id' => $otherTenant->id,
        'type' => 'image', 'name' => 'foreign.jpg', 'extension' => 'jpg', 'path' => $foreignPath, 'disk' => 'media', 'size' => 1,
    ]);

    Livewire::test('media::media-list')
        ->call('enterBulkSelectMode')
        ->set('selectedMediaIds', [$own->id, $foreign->id])
        ->call('deleteSelectedMedia');

    // Use withoutGlobalScopes() because Media has a TenantScope that would
    // hide foreign-tenant rows from a normal find() call.
    expect(Media::withoutGlobalScopes()->find($own->id))->toBeNull();
    expect(Storage::disk('media')->exists($ownPath))->toBeFalse();

    expect(Media::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
    expect(Storage::disk('media')->exists($foreignPath))->toBeTrue();
});
