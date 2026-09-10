<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Noerd\Events\TenantAppAssigned;
use Noerd\Media\Exceptions\SystemFolderProtectedException;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Services\AppFolderService;
use Noerd\Media\Tests\Support\CreatesAppFolderFixtures;
use Noerd\Models\NoerdUser;
use Noerd\Models\Tenant;

uses(Tests\TestCase::class, RefreshDatabase::class);
uses(CreatesAppFolderFixtures::class);

beforeEach(function (): void {
    Storage::fake('media');
    $this->user = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
    $this->actingAs($this->user);
    $this->tenantId = (int) $this->user->selected_tenant_id;
    $this->zzRegisterFolder();
});

describe('creating app folders', function (): void {

    it('creates the registered folder once for a tenant holding the app', function (): void {
        $this->zzAssignFolderApp($this->tenantId);
        $service = app(AppFolderService::class);

        $service->ensureForTenant($this->tenantId);
        $service->forget();
        $service->ensureForTenant($this->tenantId);

        $folders = MediaFolder::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->get();
        expect($folders)->toHaveCount(1)
            ->and($folders->first()->system_key)->toBe('zz.inbox')
            ->and($folders->first()->app_name)->toBe('ZZ-FOLDER-APP')
            ->and($folders->first()->name)->toBe('ZZ Inbox')
            ->and($folders->first()->parent_id)->toBeNull()
            ->and($folders->first()->isSystem())->toBeTrue();
    });

    it('creates nothing for a tenant that does not hold the app', function (): void {
        app(AppFolderService::class)->ensureForTenant($this->tenantId);

        expect(MediaFolder::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->count())->toBe(0);
    });

    it('resolves the folder on demand for any tenant, also without a signed-in user', function (): void {
        auth()->logout();
        $other = Tenant::factory()->create();

        $folder = app(AppFolderService::class)->resolve($other->id, 'zz.inbox');
        $again = app(AppFolderService::class)->resolve($other->id, 'zz.inbox');

        expect($folder->tenant_id)->toBe($other->id)
            ->and($folder->isSystem())->toBeTrue()
            ->and($again->id)->toBe($folder->id);
    });

    it('refuses an unregistered key', function (): void {
        app(AppFolderService::class)->resolve($this->tenantId, 'zz.unknown');
    })->throws(InvalidArgumentException::class);

    it('creates the folder the moment the app is assigned to a tenant', function (): void {
        $tenant = Tenant::factory()->create();

        TenantAppAssigned::dispatch($tenant->id, 'ZZ-FOLDER-APP');

        expect($this->zzSystemFolder($tenant->id))->not->toBeNull();
    });

    it('catches up every tenant holding the app', function (): void {
        $this->zzAssignFolderApp($this->tenantId);
        $withApp = Tenant::factory()->withApp('ZZ-FOLDER-APP')->create();
        $without = Tenant::factory()->create();

        app(AppFolderService::class)->ensureForAllTenants();

        expect($this->zzSystemFolder($this->tenantId))->not->toBeNull()
            ->and($this->zzSystemFolder($withApp->id))->not->toBeNull()
            ->and($this->zzSystemFolder($without->id))->toBeNull();
    });

    it('creates the folders when the media library is opened', function (): void {
        $this->zzAssignFolderApp($this->tenantId);

        Livewire::test('media::media-list')->assertSee('ZZ Inbox');

        expect($this->zzSystemFolder($this->tenantId))->not->toBeNull();
    });

    it('shows the label translated into the active language', function (): void {
        $this->zzAssignFolderApp($this->tenantId);
        $folder = app(AppFolderService::class)->resolve($this->tenantId, 'zz.inbox');
        Lang::addLines(['*.ZZ Inbox' => 'ZZ Eingang'], 'de');
        app()->setLocale('de');

        expect($folder->label())->toBe('ZZ Eingang')
            ->and($folder->breadcrumb()[0]['name'])->toBe('ZZ Eingang');

        Livewire::test('media::media-list')->assertSee('ZZ Eingang');
    });

    it('follows a label the module renamed, quietly', function (): void {
        $this->zzAssignFolderApp($this->tenantId);
        $folder = app(AppFolderService::class)->resolve($this->tenantId, 'zz.inbox');

        $this->zzRegisterFolder('ZZ Import');
        app(AppFolderService::class)->resolve($this->tenantId, 'zz.inbox');

        expect($folder->fresh()->name)->toBe('ZZ Import');
    });
});

describe('protection', function (): void {

    beforeEach(function (): void {
        $this->zzAssignFolderApp($this->tenantId);
        $this->folder = app(AppFolderService::class)->resolve($this->tenantId, 'zz.inbox');
    });

    it('cannot be deleted', function (): void {
        expect(fn() => $this->folder->delete())->toThrow(SystemFolderProtectedException::class)
            ->and($this->zzSystemFolder($this->tenantId))->not->toBeNull();
    });

    it('cannot be renamed or moved', function (): void {
        $parent = MediaFolder::create(['tenant_id' => $this->tenantId, 'parent_id' => null, 'name' => 'Mine']);

        expect(fn() => $this->folder->update(['name' => 'Renamed']))->toThrow(SystemFolderProtectedException::class)
            ->and(fn() => $this->folder->fresh()->update(['parent_id' => $parent->id]))->toThrow(SystemFolderProtectedException::class)
            ->and($this->folder->fresh()->name)->toBe('ZZ Inbox')
            ->and($this->folder->fresh()->parent_id)->toBeNull();
    });

    it('offers no delete button and ignores a delete call in the media library', function (): void {
        $mine = MediaFolder::create(['tenant_id' => $this->tenantId, 'parent_id' => null, 'name' => 'Mine']);

        $component = Livewire::test('media::media-list')
            ->assertSeeHtml("deleteFolder({$mine->id})")
            ->assertDontSeeHtml("deleteFolder({$this->folder->id})");

        $component->call('deleteFolder', $this->folder->id);

        expect($this->zzSystemFolder($this->tenantId))->not->toBeNull();
    });

    it('takes user folders and files, which stay deletable', function (): void {
        $child = MediaFolder::create(['tenant_id' => $this->tenantId, 'parent_id' => $this->folder->id, 'name' => '2026']);
        $file = Media::factory()->file($this->tenantId, 'inside.pdf', $child->id)->create();

        Livewire::test('media::media-list')->call('deleteFolder', $child->id);

        expect(MediaFolder::withoutGlobalScopes()->find($child->id))->toBeNull()
            ->and($file->fresh()->folder_id)->toBe($this->folder->id);
    });
});
