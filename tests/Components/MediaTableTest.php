<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaTag;
use Noerd\Models\User;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    $this->user = User::factory()->withExampleTenant()->withSelectedApp('media')->create();
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

    Livewire::test('media-list')
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

    $component = Livewire::test('media-list')
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

    $component = Livewire::test('media-list')
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

    Livewire::test('media-list')->call('deleteMedia', $media->id);

    expect(Media::find($media->id))->toBeNull();
    expect(Storage::disk('media')->exists($path))->toBeFalse();
});
