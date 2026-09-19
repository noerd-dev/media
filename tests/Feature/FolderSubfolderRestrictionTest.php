<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Noerd\Media\Exceptions\SubfoldersNotAllowedException;
use Noerd\Media\Models\MediaFolder;
use Noerd\Models\NoerdUser;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    // adminUser() attaches the tenant with the ADMIN profile — the folder
    // toggle is an admin decision.
    $this->user = NoerdUser::factory()->adminUser()->withSelectedApp('media')->create();
    $this->actingAs($this->user);
    $this->tenantId = (int) $this->user->selected_tenant_id;
});

describe('the flat-folder rule', function (): void {

    it('allows subfolders unless a folder says otherwise', function (): void {
        $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Documents']);

        expect($folder->allowsSubfolders())->toBeTrue();

        $child = MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'parent_id' => $folder->id,
            'name' => 'Invoices',
        ]);

        expect($child->exists)->toBeTrue();
    });

    it('refuses a new folder inside a flat folder', function (): void {
        $folder = MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Inbox',
            'allows_subfolders' => false,
        ]);

        expect(fn() => MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'parent_id' => $folder->id,
            'name' => 'Nested',
        ]))->toThrow(SubfoldersNotAllowedException::class);
    });

    it('refuses moving an existing folder into a flat folder', function (): void {
        $flat = MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Inbox',
            'allows_subfolders' => false,
        ]);
        $other = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Elsewhere']);

        $other->parent_id = $flat->id;

        expect(fn() => $other->save())->toThrow(SubfoldersNotAllowedException::class);
    });

    it('keeps the subfolders a folder already had when it is blocked', function (): void {
        $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Documents']);
        $child = MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'parent_id' => $folder->id,
            'name' => 'Invoices',
        ]);

        $folder->allows_subfolders = false;
        $folder->save();

        expect($child->fresh())->not->toBeNull()
            ->and($folder->fresh()->children()->count())->toBe(1);

        // ...but nothing new comes in.
        expect(fn() => MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'parent_id' => $folder->id,
            'name' => 'Receipts',
        ]))->toThrow(SubfoldersNotAllowedException::class);
    });

    it('still lets the delete cascade move children up into a blocked folder', function (): void {
        $flat = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Inbox']);
        $middle = MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'parent_id' => $flat->id,
            'name' => 'Middle',
        ]);
        $leaf = MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'parent_id' => $middle->id,
            'name' => 'Leaf',
        ]);
        $flat->forceFill(['allows_subfolders' => false])->save();

        Livewire::test('media::media-list')->call('deleteFolder', $middle->id);

        expect($leaf->fresh()->parent_id)->toBe($flat->id)
            ->and(MediaFolder::find($middle->id))->toBeNull();
    });

    it('applies to an app folder just like any other', function (): void {
        $folder = MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'name' => 'ZZ Inbox',
            'app_name' => 'ZZ-FOLDER-APP',
            'system_key' => 'zz.inbox',
            'allows_subfolders' => false,
        ]);

        expect(fn() => MediaFolder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'parent_id' => $folder->id,
            'name' => 'Nested',
        ]))->toThrow(SubfoldersNotAllowedException::class);
    });
});

describe('who may change it', function (): void {

    it('lets a tenant admin block and unblock subfolders', function (): void {
        $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Documents']);

        Livewire::test('media::media-list')->call('toggleFolderSubfolders', $folder->id);

        expect($folder->fresh()->allowsSubfolders())->toBeFalse();

        Livewire::test('media::media-list')->call('toggleFolderSubfolders', $folder->id);

        expect($folder->fresh()->allowsSubfolders())->toBeTrue();
    });

    it('lets an admin block an app folder too', function (): void {
        $folder = MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'name' => 'ZZ Inbox',
            'app_name' => 'MEDIA',
            'system_key' => 'zz.inbox',
        ]);

        Livewire::test('media::media-list')->call('toggleFolderSubfolders', $folder->id);

        expect($folder->fresh()->allowsSubfolders())->toBeFalse();
    });

    it('ignores the toggle for a user who is not a tenant admin', function (): void {
        // A plain member of their own tenant (withExampleTenant attaches
        // without a profile).
        $member = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
        $this->actingAs($member);
        $folder = MediaFolder::create(['tenant_id' => $member->selected_tenant_id, 'name' => 'Documents']);

        Livewire::test('media::media-list')
            ->assertViewHas('canManageFolders', false)
            ->call('toggleFolderSubfolders', $folder->id);

        expect($folder->fresh()->allowsSubfolders())->toBeTrue();
    });

    it('shows the checkbox inside a folder to an admin only', function (): void {
        $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Documents']);

        Livewire::test('media::media-list')
            ->call('openFolder', $folder->id)
            ->assertSee(__('Allow subfolders'))
            ->assertSeeHtml('wire:click="toggleFolderSubfolders(' . $folder->id . ')"');

        $member = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
        $this->actingAs($member);
        $memberFolder = MediaFolder::create(['tenant_id' => $member->selected_tenant_id, 'name' => 'Documents']);

        Livewire::test('media::media-list')
            ->call('openFolder', $memberFolder->id)
            ->assertDontSee(__('Allow subfolders'));
    });

    it('never toggles a folder of another tenant', function (): void {
        $foreign = MediaFolder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId + 1,
            'name' => 'Foreign',
        ]);

        Livewire::test('media::media-list')->call('toggleFolderSubfolders', $foreign->id);

        expect(MediaFolder::withoutGlobalScopes()->find($foreign->id)->allowsSubfolders())->toBeTrue();
    });
});

describe('the library chrome', function (): void {

    it('offers no way to create a folder inside a flat folder', function (): void {
        $folder = MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Inbox',
            'allows_subfolders' => false,
        ]);

        Livewire::test('media::media-list')
            ->call('openFolder', $folder->id)
            ->assertViewHas('currentFolderTakesSubfolders', false)
            ->call('openCreateFolderModal')
            ->assertNotDispatched('noerdModal');
    });

    it('still offers it in an ordinary folder', function (): void {
        $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Documents']);

        Livewire::test('media::media-list')
            ->call('openFolder', $folder->id)
            ->assertViewHas('currentFolderTakesSubfolders', true)
            ->call('openCreateFolderModal')
            ->assertDispatched('noerdModal');
    });

    it('refuses the create modal itself for a flat parent', function (): void {
        $folder = MediaFolder::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Inbox',
            'allows_subfolders' => false,
        ]);

        Livewire::test('media::folder-create', ['parentFolderId' => $folder->id])
            ->set('name', 'Nested')
            ->call('store')
            ->assertHasErrors('name');

        expect(MediaFolder::where('parent_id', $folder->id)->exists())->toBeFalse();
    });
});
