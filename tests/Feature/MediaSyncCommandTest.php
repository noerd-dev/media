<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Services\MediaUsageRegistry;
use Noerd\Models\NoerdUser;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('media');
    $this->user = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
    $this->actingAs($this->user);
    $this->tenantId = (int) $this->user->selected_tenant_id;
});

it('imports a file that was copied into the tenant root by hand', function (): void {
    Storage::disk('media')->put("{$this->tenantId}/beleg.pdf", 'PDF');

    $this->artisan('media:sync', ['--tenant' => $this->tenantId])->assertSuccessful();

    $media = Media::withoutGlobalScopes()->where('path', "{$this->tenantId}/beleg.pdf")->first();

    expect($media)->not->toBeNull()
        ->and($media->name)->toBe('beleg.pdf')
        ->and($media->extension)->toBe('pdf')
        ->and($media->folder_id)->toBeNull()
        ->and((int) $media->size)->toBe(3);
});

it('creates the whole folder chain of a hand-copied tree', function (): void {
    Storage::disk('media')->put("{$this->tenantId}/Vertraege/2026/mietvertrag.pdf", 'PDF');

    $this->artisan('media:sync', ['--tenant' => $this->tenantId])->assertSuccessful();

    $parent = MediaFolder::withoutGlobalScopes()->where('path_segment', 'Vertraege')->first();
    $child = MediaFolder::withoutGlobalScopes()->where('path_segment', '2026')->first();

    expect($parent)->not->toBeNull()
        ->and($child)->not->toBeNull()
        ->and((int) $child->parent_id)->toBe((int) $parent->id);

    $media = Media::withoutGlobalScopes()->first();
    expect((int) $media->folder_id)->toBe((int) $child->id);
});

it('files a copied-in document into the folder that already exists', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Rechnungen']);
    Storage::disk('media')->put("{$this->tenantId}/Rechnungen/beleg.pdf", 'PDF');

    $this->artisan('media:sync', ['--tenant' => $this->tenantId])->assertSuccessful();

    expect(MediaFolder::withoutGlobalScopes()->count())->toBe(1);
    expect((int) Media::withoutGlobalScopes()->first()->folder_id)->toBe((int) $folder->id);
});

it('writes nothing on a dry run', function (): void {
    Storage::disk('media')->put("{$this->tenantId}/Neu/beleg.pdf", 'PDF');

    $this->artisan('media:sync', ['--tenant' => $this->tenantId, '--dry-run' => true])->assertSuccessful();

    expect(Media::withoutGlobalScopes()->count())->toBe(0)
        ->and(MediaFolder::withoutGlobalScopes()->count())->toBe(0);
});

it('skips a file whose extension is not allowed', function (): void {
    config()->set('media.allowed_extensions', ['pdf']);
    Storage::disk('media')->put("{$this->tenantId}/script.exe", 'X');

    $this->artisan('media:sync', ['--tenant' => $this->tenantId])->assertSuccessful();

    expect(Media::withoutGlobalScopes()->count())->toBe(0);
});

it('ignores the generated thumbnail directory', function (): void {
    Storage::disk('media')->put("{$this->tenantId}/.thumbnails/thumb_x.jpg", 'THUMB');

    $this->artisan('media:sync', ['--tenant' => $this->tenantId])->assertSuccessful();

    expect(Media::withoutGlobalScopes()->count())->toBe(0)
        ->and(MediaFolder::withoutGlobalScopes()->count())->toBe(0);
});

it('leaves content of other modules on the same disk untouched', function (): void {
    Storage::disk('media')->put('crm/print-mailings/7/template.pdf', 'PDF');

    $this->artisan('media:sync', ['--tenant' => $this->tenantId])->assertSuccessful();

    expect(Media::withoutGlobalScopes()->count())->toBe(0)
        ->and(MediaFolder::withoutGlobalScopes()->count())->toBe(0);
    Storage::disk('media')->assertExists('crm/print-mailings/7/template.pdf');
});

it('reports a record whose file is gone without deleting it', function (): void {
    $media = Media::factory()->file($this->tenantId, 'weg.pdf')->create();

    $this->artisan('media:sync', ['--tenant' => $this->tenantId])
        ->expectsOutputToContain('weg.pdf')
        ->assertSuccessful();

    expect(Media::withoutGlobalScopes()->find($media->id))->not->toBeNull();
});

it('deletes a record without a file only with --prune', function (): void {
    $media = Media::factory()->file($this->tenantId, 'weg.pdf')->create();

    $this->artisan('media:sync', ['--tenant' => $this->tenantId, '--prune' => true])->assertSuccessful();

    expect(Media::withoutGlobalScopes()->find($media->id))->toBeNull();
});

it('keeps a missing file that another module still needs', function (): void {
    $media = Media::factory()->file($this->tenantId, 'weg.pdf')->create();

    app(MediaUsageRegistry::class)->register(
        'zz',
        fn(Media $candidate): ?string => $candidate->id === $media->id ? 'Used by 2 receipts' : null,
    );

    $this->artisan('media:sync', ['--tenant' => $this->tenantId, '--prune' => true])->assertSuccessful();

    expect(Media::withoutGlobalScopes()->find($media->id))->not->toBeNull();
});

it('reuses the folder it created on a second run', function (): void {
    Storage::disk('media')->put("{$this->tenantId}/Verträge/2026/mietvertrag.pdf", 'PDF');

    $this->artisan('media:sync', ['--tenant' => $this->tenantId])->assertSuccessful();
    $this->artisan('media:sync', ['--tenant' => $this->tenantId])->assertSuccessful();

    // The directory name is stored verbatim, so the second run finds the folder
    // instead of creating a duplicate next to it.
    expect(MediaFolder::withoutGlobalScopes()->count())->toBe(2)
        ->and(Media::withoutGlobalScopes()->count())->toBe(1);

    $folder = MediaFolder::withoutGlobalScopes()->whereNull('parent_id')->first();
    expect($folder->path_segment)->toBe('Verträge');
});
