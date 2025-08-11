<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Noerd\Noerd\Models\User;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaLabel;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('images');
    $this->user = User::factory()->create();
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

    Volt::test('media-table')
        ->set('files', [$filePayload])
        ->call('store');

    expect(Media::count())->toBe($before + 1);
    $media = Media::latest('id')->first();
    expect($media->tenant_id)->toBe($this->user->selected_tenant_id)
        ->and($media->disk)->toBe('images')
        ->and(Storage::disk('images')->exists($media->path))->toBeTrue();
});

it('can add, attach and detach labels for selected media', function (): void {
    $media = Media::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'type' => 'image',
        'name' => 'bar.jpg',
        'extension' => 'jpg',
        'path' => $this->user->selected_tenant_id . '/bar.jpg',
        'disk' => 'images',
        'size' => 99,
        'ai_access' => true,
    ]);

    $component = Volt::test('media-table')
        ->call('selectMedia', $media->id)
        ->set('labelName', 'TestLabel')
        ->call('addLabel');

    $label = MediaLabel::where('tenant_id', $this->user->selected_tenant_id)->where('name', 'TestLabel')->first();
    expect($label)->not->toBeNull();
    expect($media->fresh()->labels()->pluck('name'))->toContain('TestLabel');

    // Attach existing different label
    $existing = MediaLabel::firstOrCreate([
        'tenant_id' => $this->user->selected_tenant_id,
        'name' => 'AnotherLabel',
    ]);

    $component->call('attachLabel', $existing->id);
    expect($media->fresh()->labels()->pluck('name'))->toContain('AnotherLabel');

    // Detach
    $component->call('detachLabel', $existing->id);
    expect($media->fresh()->labels()->pluck('name'))->not->toContain('AnotherLabel');
});

it('filters media by multiple labels (AND)', function (): void {
    // Create labels
    $labelA = MediaLabel::create(['tenant_id' => $this->user->selected_tenant_id, 'name' => 'A']);
    $labelB = MediaLabel::create(['tenant_id' => $this->user->selected_tenant_id, 'name' => 'B']);

    // Media 1: A only
    $m1 = Media::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'type' => 'image', 'name' => 'm1.jpg', 'extension' => 'jpg', 'path' => $this->user->selected_tenant_id . '/m1.jpg', 'disk' => 'images', 'size' => 1, 'ai_access' => true,
    ]);
    $m1->labels()->sync([$labelA->id]);

    // Media 2: B only
    $m2 = Media::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'type' => 'image', 'name' => 'm2.jpg', 'extension' => 'jpg', 'path' => $this->user->selected_tenant_id . '/m2.jpg', 'disk' => 'images', 'size' => 1, 'ai_access' => true,
    ]);
    $m2->labels()->sync([$labelB->id]);

    // Media 3: A and B
    $m3 = Media::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'type' => 'image', 'name' => 'm3.jpg', 'extension' => 'jpg', 'path' => $this->user->selected_tenant_id . '/m3.jpg', 'disk' => 'images', 'size' => 1, 'ai_access' => true,
    ]);
    $m3->labels()->sync([$labelA->id, $labelB->id]);

    $component = Volt::test('media-table')
        ->set('filterLabelIds', [$labelA->id, $labelB->id]);

    $rows = $component->viewData('rows');
    $ids = collect($rows)->pluck('id');

    expect($ids)->toContain($m3->id);
    expect($ids)->not->toContain($m1->id);
    expect($ids)->not->toContain($m2->id);
});

it('deletes media and removes file from disk', function (): void {
    $path = $this->user->selected_tenant_id . '/todelete.jpg';
    Storage::disk('images')->put($path, 'x');

    $media = Media::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'type' => 'image', 'name' => 'todelete.jpg', 'extension' => 'jpg', 'path' => $path, 'disk' => 'images', 'size' => 1, 'ai_access' => true,
    ]);

    Volt::test('media-table')->call('deleteMedia', $media->id);

    expect(Media::find($media->id))->toBeNull();
    expect(Storage::disk('images')->exists($path))->toBeFalse();
});


