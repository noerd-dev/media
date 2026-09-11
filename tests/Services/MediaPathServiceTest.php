<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Services\MediaPathService;
use Noerd\Models\NoerdUser;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')->create();
    $this->actingAs($this->user);
    $this->tenantId = (int) $this->user->selected_tenant_id;
    $this->paths = app(MediaPathService::class);
});

it('strips everything a directory name must not contain', function (string $input, string $expected): void {
    expect($this->paths->segmentFor($input))->toBe($expected);
})->with([
    'directory separators' => ['../etc', 'etc'],
    'backslashes' => ['a\\b', 'ab'],
    'leading dot' => ['.hidden', 'hidden'],
    'umlauts survive' => ['Verträge', 'Verträge'],
    'collapsed whitespace' => ["Alte   Belege\t2024", 'Alte Belege 2024'],
    'wildcards' => ['Re*chnung?', 'Rechnung'],
]);

it('falls back to a generic segment when nothing survives sanitizing', function (): void {
    expect($this->paths->segmentFor('...'))->toBe('folder');
});

it('gives same-named siblings distinct directories', function (): void {
    $first = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Belege']);
    $second = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Belege']);

    expect($first->path_segment)->toBe('Belege')
        ->and($second->path_segment)->toBe('Belege-2');
});

it('lets the same name live under different parents', function (): void {
    $parent = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Rechnungen']);

    $root = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => '2026']);
    $nested = MediaFolder::create(['tenant_id' => $this->tenantId, 'parent_id' => $parent->id, 'name' => '2026']);

    expect($root->path_segment)->toBe('2026')
        ->and($nested->path_segment)->toBe('2026');
});

it('builds the path from the whole folder chain', function (): void {
    $parent = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Rechnungen']);
    $child = MediaFolder::create(['tenant_id' => $this->tenantId, 'parent_id' => $parent->id, 'name' => '2026']);

    expect($this->paths->pathFor($this->tenantId, $child, 'beleg.pdf'))
        ->toBe("{$this->tenantId}/Rechnungen/2026/beleg.pdf");
});

it('puts a file without a folder in the tenant root', function (): void {
    expect($this->paths->pathFor($this->tenantId, null, 'beleg.pdf'))
        ->toBe("{$this->tenantId}/beleg.pdf");
});

it('numbers a file name that the target folder already holds', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Belege']);
    Media::factory()->file($this->tenantId, 'beleg.pdf', $folder->id)->create();

    expect($this->paths->uniqueFilename($this->tenantId, $folder, 'beleg.pdf'))->toBe('beleg-2.pdf');
});

it('keeps the name when only another folder holds it', function (): void {
    $one = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'A']);
    $two = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'B']);
    Media::factory()->file($this->tenantId, 'beleg.pdf', $one->id)->create();

    expect($this->paths->uniqueFilename($this->tenantId, $two, 'beleg.pdf'))->toBe('beleg.pdf');
});

it('ignores the record itself when checking for a name clash', function (): void {
    $folder = MediaFolder::create(['tenant_id' => $this->tenantId, 'name' => 'Belege']);
    $media = Media::factory()->file($this->tenantId, 'beleg.pdf', $folder->id)->create();

    expect($this->paths->uniqueFilename($this->tenantId, $folder, 'beleg.pdf', $media->id))->toBe('beleg.pdf');
});

it('keeps generated thumbnails out of the mirrored tree', function (): void {
    expect($this->paths->thumbnailDirectory($this->tenantId))->toBe("{$this->tenantId}/.thumbnails")
        ->and($this->paths->isReservedDirectory('.thumbnails'))->toBeTrue()
        ->and($this->paths->isReservedDirectory('Rechnungen'))->toBeFalse();
});

it('keeps a segment the caller set explicitly', function (): void {
    // The reconciler reads the directory name off the disk — that name is the
    // truth and must not be re-derived from the display name.
    $folder = MediaFolder::create([
        'tenant_id' => $this->tenantId,
        'name' => 'Anything',
        'path_segment' => 'Verträge',
    ]);

    expect($folder->path_segment)->toBe('Verträge');
});
