<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Services\AppFolderService;
use Noerd\Media\Tests\Support\CreatesAppFolderFixtures;
use Noerd\Models\NoerdUser;

uses(Tests\TestCase::class, RefreshDatabase::class);
uses(CreatesAppFolderFixtures::class);

/**
 * An app folder with a file in it, owned by an app the user may use at first.
 * Every test then decides whether the user keeps that access.
 */
beforeEach(function (): void {
    Storage::fake('media');
    $this->user = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
    $this->actingAs($this->user);
    $this->tenantId = (int) $this->user->selected_tenant_id;
    $this->zzRegisterFolder();
    $this->zzAssignFolderApp($this->tenantId);
    $this->folder = app(AppFolderService::class)->resolve($this->tenantId, 'zz.inbox');
    $this->file = Media::factory()->file($this->tenantId, 'secret.jpg', $this->folder->id)->create();
    Storage::disk('media')->put($this->file->path, 'BYTES');
});

it('shows the folder and its files to a user who may use the app', function (): void {
    $component = Livewire::test('media::media-list');

    expect($component->viewData('folders')->pluck('id')->all())->toContain($this->folder->id);

    $component->call('openFolder', $this->folder->id)->assertSee('secret.jpg')
        ->set('search', 'secret')->assertSee('secret.jpg');

    Livewire::test('media::folder-picker', ['mediaIds' => [$this->file->id]])->assertSee('ZZ Inbox');
});

it('hides the folder and its files once the tenant no longer holds the app', function (): void {
    $this->zzUnassignFolderApp($this->tenantId);

    $component = Livewire::test('media::media-list');

    expect($component->viewData('folders')->pluck('id')->all())->not->toContain($this->folder->id)
        ->and(MediaFolder::find($this->folder->id))->toBeNull()
        ->and(Media::find($this->file->id))->toBeNull();

    // The global search ignores the folder context — it must not leak the file.
    $component->set('search', 'secret')->assertDontSee('secret.jpg');
});

it('hides the folder when the app permission denies the app, as noerd-plus grants do', function (): void {
    $this->zzDenyFolderApp();

    expect(MediaFolder::find($this->folder->id))->toBeNull()
        ->and(Media::find($this->file->id))->toBeNull();

    Livewire::test('media::media-list')->set('search', 'secret')->assertDontSee('secret.jpg');
    Livewire::test('media::folder-picker', ['mediaIds' => [$this->file->id]])->assertDontSee('ZZ Inbox');
});

it('hides the folders and files below it as well', function (): void {
    $child = MediaFolder::create(['tenant_id' => $this->tenantId, 'parent_id' => $this->folder->id, 'name' => 'By year']);
    $nested = Media::factory()->file($this->tenantId, 'nested.jpg', $child->id)->create();
    $this->zzDenyFolderApp();

    expect(MediaFolder::find($child->id))->toBeNull()
        ->and(Media::find($nested->id))->toBeNull();

    Livewire::test('media::folder-picker', ['mediaIds' => [$nested->id]])->assertDontSee('By year');
});

it('does not let a hidden folder become a target through its id', function (): void {
    $this->zzDenyFolderApp();
    $free = Media::factory()->file($this->tenantId, 'free.pdf')->create();

    Livewire::test('media::media-list')
        ->call('openFolder', $this->folder->id)
        ->assertSet('currentFolderId', null)
        ->call('moveMediaToFolder', [$free->id], $this->folder->id);

    Livewire::test('media::folder-create', ['parentFolderId' => $this->folder->id])
        ->set('name', 'Sneaky')
        ->call('store')
        ->assertNotDispatched('mediaFolderCreated');

    expect($free->fresh()->folder_id)->toBeNull()
        ->and(MediaFolder::withoutGlobalScopes()->where('name', 'Sneaky')->exists())->toBeFalse();
});

it('drops a hidden folder id arriving through the URL', function (): void {
    $this->zzDenyFolderApp();

    Livewire::withQueryParams(['folder' => $this->folder->id])
        ->test('media::media-list')
        ->assertSet('currentFolderId', null);
});

it('answers the file routes of a hidden file with 404', function (): void {
    config(['media.private' => true]);
    $this->zzDenyFolderApp();

    $this->get(route('media.file', $this->file))->assertNotFound();
    $this->get(route('media.thumbnail', $this->file))->assertNotFound();
});

it('filters nothing without a signed-in user', function (): void {
    $this->zzDenyFolderApp();
    auth()->logout();

    expect(MediaFolder::find($this->folder->id))->not->toBeNull()
        ->and(Media::find($this->file->id))->not->toBeNull();
});
