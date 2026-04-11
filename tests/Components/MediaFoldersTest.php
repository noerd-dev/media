<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Models\NoerdUser;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    $this->user = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
    $this->actingAs($this->user);
});

function makeMedia(int $tenantId, ?int $folderId = null, string $name = 'foo.jpg'): Media
{
    return Media::create([
        'tenant_id' => $tenantId,
        'folder_id' => $folderId,
        'type' => 'image',
        'name' => $name,
        'extension' => 'jpg',
        'path' => $tenantId . '/' . uniqid() . '_' . $name,
        'disk' => 'media',
        'size' => 100,
    ]);
}

it('creates a folder at root via the create-folder modal', function (): void {
    Livewire::test('media-folder-create', ['parentFolderId' => null])
        ->set('name', 'Documents')
        ->call('store')
        ->assertDispatched('mediaFolderCreated')
        ->assertDispatched('closeTopModal');

    $folder = MediaFolder::where('tenant_id', $this->user->selected_tenant_id)->first();
    expect($folder)->not->toBeNull()
        ->and($folder->name)->toBe('Documents')
        ->and($folder->parent_id)->toBeNull();
});

it('creates a nested folder under the current folder via the create-folder modal', function (): void {
    $parent = MediaFolder::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'parent_id' => null,
        'name' => 'Parent',
    ]);

    Livewire::test('media-folder-create', ['parentFolderId' => $parent->id])
        ->set('name', 'Child')
        ->call('store')
        ->assertDispatched('mediaFolderCreated')
        ->assertDispatched('closeTopModal');

    $child = MediaFolder::where('tenant_id', $this->user->selected_tenant_id)
        ->where('name', 'Child')
        ->first();

    expect($child)->not->toBeNull()
        ->and($child->parent_id)->toBe($parent->id);
});

it('does not create a folder when the name is empty', function (): void {
    Livewire::test('media-folder-create', ['parentFolderId' => null])
        ->set('name', '')
        ->call('store')
        ->assertHasErrors(['name' => 'required'])
        ->assertNotDispatched('mediaFolderCreated');

    expect(MediaFolder::count())->toBe(0);
});

it('opens the create-folder modal with the current folder as parent', function (): void {
    $parent = MediaFolder::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'parent_id' => null,
        'name' => 'Parent',
    ]);

    Livewire::test('media-list')
        ->call('openFolder', $parent->id)
        ->call('openCreateFolderModal')
        ->assertDispatched(
            'noerdModal',
            modalComponent: 'media-folder-create',
            arguments: ['parentFolderId' => $parent->id],
        );
});

it('builds a breadcrumb walking up parents', function (): void {
    $a = MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => null, 'name' => 'A']);
    $b = MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => $a->id, 'name' => 'B']);
    $c = MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => $b->id, 'name' => 'C']);

    $crumb = $c->fresh()->breadcrumb();

    expect($crumb)->toHaveCount(3)
        ->and($crumb[0]['name'])->toBe('A')
        ->and($crumb[1]['name'])->toBe('B')
        ->and($crumb[2]['name'])->toBe('C');
});

it('shows only files in the current folder when no filters are active', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => null, 'name' => 'Inside']);
    $rootFile = makeMedia($this->user->selected_tenant_id, null, 'root.jpg');
    $insideFile = makeMedia($this->user->selected_tenant_id, $folder->id, 'inside.jpg');

    // At root: only the root file is visible
    Livewire::test('media-list')
        ->assertSee('root.jpg')
        ->assertDontSee('inside.jpg')
        // Navigate into the folder: only inside file is visible
        ->call('openFolder', $folder->id)
        ->assertSee('inside.jpg')
        ->assertDontSee('root.jpg');
});

it('shows files from all folders when search is active (global search)', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => null, 'name' => 'Box']);
    makeMedia($this->user->selected_tenant_id, null, 'alpha.jpg');
    makeMedia($this->user->selected_tenant_id, $folder->id, 'alphabet.jpg');

    // No search: only root file at root
    Livewire::test('media-list')
        ->assertSee('alpha.jpg')
        ->assertDontSee('alphabet.jpg')
        // With search: both files match regardless of folder
        ->set('search', 'alpha')
        ->assertSee('alpha.jpg')
        ->assertSee('alphabet.jpg');
});

it('moves a file into a folder via moveMediaToFolder', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => null, 'name' => 'Target']);
    $media = makeMedia($this->user->selected_tenant_id);

    Livewire::test('media-list')
        ->call('moveMediaToFolder', [$media->id], $folder->id);

    expect($media->fresh()->folder_id)->toBe($folder->id);
});

it('bulk-moves selected files into a folder', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => null, 'name' => 'Bulk']);
    $a = makeMedia($this->user->selected_tenant_id, null, 'a.jpg');
    $b = makeMedia($this->user->selected_tenant_id, null, 'b.jpg');
    $c = makeMedia($this->user->selected_tenant_id, null, 'c.jpg');

    Livewire::test('media-list')
        ->call('enterBulkSelectMode')
        ->call('toggleMediaSelection', $a->id)
        ->call('toggleMediaSelection', $b->id)
        ->call('moveMediaToFolder', [$a->id, $b->id], $folder->id);

    expect($a->fresh()->folder_id)->toBe($folder->id)
        ->and($b->fresh()->folder_id)->toBe($folder->id)
        ->and($c->fresh()->folder_id)->toBeNull();
});

it('cascades children folders and files to parent on folder delete', function (): void {
    $parent = MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => null, 'name' => 'Parent']);
    $middle = MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => $parent->id, 'name' => 'Middle']);
    $leaf = MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => $middle->id, 'name' => 'Leaf']);
    $file = makeMedia($this->user->selected_tenant_id, $middle->id);

    Livewire::test('media-list')
        ->call('deleteFolder', $middle->id);

    expect(MediaFolder::find($middle->id))->toBeNull()
        ->and($leaf->fresh()->parent_id)->toBe($parent->id)
        ->and($file->fresh()->folder_id)->toBe($parent->id);
});

it('scopes folders by tenant', function (): void {
    $myTenantId = $this->user->selected_tenant_id;
    $otherTenantId = $myTenantId + 999;

    MediaFolder::create(['tenant_id' => $myTenantId, 'parent_id' => null, 'name' => 'Mine']);
    MediaFolder::create(['tenant_id' => $otherTenantId, 'parent_id' => null, 'name' => 'Theirs']);

    $folders = MediaFolder::where('tenant_id', $myTenantId)->get();

    expect($folders)->toHaveCount(1)
        ->and($folders->first()->name)->toBe('Mine');
});

it('moves a file out of a folder back to root', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => null, 'name' => 'Holder']);
    $media = makeMedia($this->user->selected_tenant_id, $folder->id);

    Livewire::test('media-list')
        ->call('moveMediaToFolder', [$media->id], null);

    expect($media->fresh()->folder_id)->toBeNull();
});

it('uploads files into the current folder', function (): void {
    $folder = MediaFolder::create([
        'tenant_id' => $this->user->selected_tenant_id,
        'parent_id' => null,
        'name' => 'Uploads',
    ]);

    $tmpFile = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($tmpFile, 'fake content');

    $filePayload = [
        'name' => 'in-folder.jpg',
        'extension' => 'txt',
        'size' => 12,
        'path' => $tmpFile,
    ];

    Livewire::test('media-list')
        ->call('openFolder', $folder->id)
        ->set('files', [$filePayload])
        ->call('store');

    $media = Media::where('name', 'in-folder.jpg')->first();
    expect($media)->not->toBeNull()
        ->and($media->folder_id)->toBe($folder->id);
});

it('uploads files at root with null folder_id when no folder selected', function (): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($tmpFile, 'fake content');

    $filePayload = [
        'name' => 'at-root.jpg',
        'extension' => 'txt',
        'size' => 12,
        'path' => $tmpFile,
    ];

    Livewire::test('media-list')
        ->set('files', [$filePayload])
        ->call('store');

    $media = Media::where('name', 'at-root.jpg')->first();
    expect($media)->not->toBeNull()
        ->and($media->folder_id)->toBeNull();
});

it('hides folder list when filters are active', function (): void {
    MediaFolder::create(['tenant_id' => $this->user->selected_tenant_id, 'parent_id' => null, 'name' => 'Visible']);

    $component = Livewire::test('media-list');
    expect($component->viewData('folders'))->toHaveCount(1);

    $component->set('search', 'anything');
    expect($component->viewData('folders'))->toHaveCount(0);
});
