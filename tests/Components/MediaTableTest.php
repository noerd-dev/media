<?php

declare(strict_types=1);

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

    @unlink($tmpFile);

    expect(Media::count())->toBe($before + 1);
    $media = Media::latest('id')->first();
    expect($media->tenant_id)->toBe($this->user->selected_tenant_id)
        ->and($media->disk)->toBe('media')
        // No folder is open, so the upload lands at the root.
        ->and($media->folder_id)->toBeNull()
        ->and(Storage::disk('media')->exists($media->path))->toBeTrue();
});

it('reflects a project override of the upload config in the dropzone rules', function (): void {
    config([
        'media.allowed_extensions' => ['png', 'gif'],
        'media.max_upload_size' => 2048,
    ]);

    Livewire::test('media::media-list')
        ->assertSeeHtml('mimes:png,gif')
        ->assertSeeHtml('max:2048');
});

it('can add, attach and detach tags for selected media', function (): void {
    $media = Media::factory()->file($this->user->selected_tenant_id, 'bar.jpg')->create();

    $component = Livewire::test('media::media-list')
        ->call('toggleMediaSelection', $media->id)
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
    $m1 = Media::factory()->file($this->user->selected_tenant_id, 'm1.jpg')->create();
    $m1->tags()->sync([$tagA->id]);

    // Media 2: B only
    $m2 = Media::factory()->file($this->user->selected_tenant_id, 'm2.jpg')->create();
    $m2->tags()->sync([$tagB->id]);

    // Media 3: A and B
    $m3 = Media::factory()->file($this->user->selected_tenant_id, 'm3.jpg')->create();
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

    $media = Media::factory()->file($this->user->selected_tenant_id, 'todelete.jpg')->create();

    Livewire::test('media::media-list')->call('deleteMedia', $media->id);

    expect(Media::find($media->id))->toBeNull();
    expect(Storage::disk('media')->exists($path))->toBeFalse();
});

it('toggles media ids into and out of the selection array', function (): void {
    $tenantId = $this->user->selected_tenant_id;

    $first = Media::factory()->file($tenantId, 'first.jpg')->create();
    $second = Media::factory()->file($tenantId, 'second.jpg')->create();

    $component = Livewire::test('media::media-list')
        ->assertSet('selectedMediaIds', [])
        ->assertSet('selected', null)
        ->call('toggleMediaSelection', $first->id)
        ->assertSet('selectedMediaIds', [$first->id]);

    // Single-selection loads the detail model.
    expect($component->get('selected')?->id)->toBe($first->id);

    // Adding a second id drops the detail model (actions panel takes over).
    $component->call('toggleMediaSelection', $second->id)
        ->assertSet('selectedMediaIds', [$first->id, $second->id])
        ->assertSet('selected', null);

    // Toggling the first id off brings us back to a single-selection detail.
    $component->call('toggleMediaSelection', $first->id)
        ->assertSet('selectedMediaIds', [$second->id]);

    expect($component->get('selected')?->id)->toBe($second->id);

    // Toggling the remaining id off empties everything.
    $component->call('toggleMediaSelection', $second->id)
        ->assertSet('selectedMediaIds', [])
        ->assertSet('selected', null);
});

it('deletes multiple selected media items and removes their files', function (): void {
    $tenantId = $this->user->selected_tenant_id;

    $items = collect(['a.jpg', 'b.jpg', 'c.jpg'])->map(function (string $name) use ($tenantId): Media {
        $path = $tenantId . '/' . $name;
        Storage::disk('media')->put($path, 'x');

        return Media::factory()->file($tenantId, $name)->create();
    });

    $toDelete = [$items[0]->id, $items[2]->id];

    Livewire::test('media::media-list')
        ->set('selectedMediaIds', $toDelete)
        ->call('deleteSelectedMedia')
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

        return Media::factory()->file($tenantId, $name)->create();
    });

    $component = Livewire::test('media::media-list')
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

    $match = Media::factory()->file($tenantId, 'invoice-march.pdf')->create();
    $unrelated = Media::factory()->file($tenantId, 'photo.jpg')->create();

    $component = Livewire::test('media::media-list')->set('search', 'invoice');
    $ids = collect($component->viewData('listConfig')['rows'])->pluck('id');

    expect($ids)->toContain($match->id);
    expect($ids)->not->toContain($unrelated->id);
});

it('filters media by extension via the listFilters picklist', function (): void {
    $tenantId = $this->user->selected_tenant_id;

    $jpg = Media::factory()->file($tenantId, 'a.jpg')->create();
    $pdf = Media::factory()->file($tenantId, 'b.pdf')->create();

    $component = Livewire::test('media::media-list')
        ->set('listFilters.extension', 'pdf');

    $ids = collect($component->viewData('listConfig')['rows'])->pluck('id');

    expect($ids)->toContain($pdf->id);
    expect($ids)->not->toContain($jpg->id);
});

it('exposes an extension filter with the distinct extensions of the current tenant', function (): void {
    $tenantId = $this->user->selected_tenant_id;

    foreach (['jpg', 'pdf', 'jpg', 'png'] as $i => $ext) {
        Media::factory()->file($tenantId, "f{$i}.{$ext}")->create();
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
    $own = Media::factory()->file($this->user->selected_tenant_id, 'own.jpg')->create();

    $foreignPath = $otherTenant->id . '/foreign.jpg';
    Storage::disk('media')->put($foreignPath, 'x');
    $foreign = Media::factory()->file($otherTenant->id, 'foreign.jpg')->create();

    Livewire::test('media::media-list')
        ->set('selectedMediaIds', [$own->id, $foreign->id])
        ->call('deleteSelectedMedia');

    // Use withoutGlobalScopes() because Media has a TenantScope that would
    // hide foreign-tenant rows from a normal find() call.
    expect(Media::withoutGlobalScopes()->find($own->id))->toBeNull();
    expect(Storage::disk('media')->exists($ownPath))->toBeFalse();

    expect(Media::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
    expect(Storage::disk('media')->exists($foreignPath))->toBeTrue();
});

it('renders a file-type tile instead of a broken image when a media has no thumbnail', function (): void {
    Media::factory()->create([
        'tenant_id' => $this->user->selected_tenant_id,
        'name' => 'contract.pdf',
        'extension' => 'pdf',
        'path' => $this->user->selected_tenant_id . '/contract.pdf',
        'thumbnail' => null,
    ]);

    Livewire::test('media::media-list')
        ->assertSeeHtml('>pdf</span>')
        ->assertDontSeeHtml('src="' . Storage::disk('media')->url($this->user->selected_tenant_id . '/contract.pdf') . '"');
});

it('renders the generated thumbnail when one exists', function (): void {
    Media::factory()->create([
        'tenant_id' => $this->user->selected_tenant_id,
        'name' => 'contract.pdf',
        'extension' => 'pdf',
        'path' => $this->user->selected_tenant_id . '/contract.pdf',
        'thumbnail' => $this->user->selected_tenant_id . '/thumbnails/pdf_abc.jpg',
    ]);

    Livewire::test('media::media-list')
        ->assertSeeHtml(Storage::disk('media')->url($this->user->selected_tenant_id . '/thumbnails/pdf_abc.jpg'));
});
