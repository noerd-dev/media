<?php

declare(strict_types=1);

use Noerd\Media\Support\FileTypeIcon;

uses(Tests\TestCase::class);

it('gives a pdf its own icon and colour', function (): void {
    expect(FileTypeIcon::for('pdf'))->toBe([
        'icon' => 'document-text',
        'classes' => 'bg-red-50 text-red-600',
    ]);
});

it('normalizes the extension before looking it up', function (): void {
    $expected = FileTypeIcon::for('pdf');

    expect(FileTypeIcon::for('PDF'))->toBe($expected)
        ->and(FileTypeIcon::for('.pdf'))->toBe($expected)
        ->and(FileTypeIcon::for('.PDF'))->toBe($expected);
});

it('groups the office formats by their family', function (): void {
    expect(FileTypeIcon::for('xlsx')['icon'])->toBe('table-cells')
        ->and(FileTypeIcon::for('csv')['icon'])->toBe('table-cells')
        ->and(FileTypeIcon::for('docx')['icon'])->toBe('document-text')
        ->and(FileTypeIcon::for('pptx')['icon'])->toBe('presentation-chart-bar')
        ->and(FileTypeIcon::for('zip')['icon'])->toBe('archive-box');
});

it('falls back to a generic document for an unknown or empty extension', function (): void {
    $generic = ['icon' => 'document', 'classes' => 'bg-gray-100 text-gray-500'];

    expect(FileTypeIcon::for('xyz'))->toBe($generic)
        ->and(FileTypeIcon::for(''))->toBe($generic);
});

it('names only icons the heroicons package actually ships', function (): void {
    // A typo in the map would render an empty tile at runtime, not an error.
    $icons = collect(['pdf', 'docx', 'xlsx', 'pptx', 'zip', 'mp4', 'mp3', 'txt', 'xyz'])
        ->map(fn(string $extension): string => FileTypeIcon::for($extension)['icon'])
        ->unique();

    foreach ($icons as $icon) {
        // Blade resolves the anonymous component `heroicons::outline.x` to the
        // view `heroicons::components.outline.x`.
        expect(view()->exists('heroicons::components.outline.' . $icon))
            ->toBeTrue("heroicons::outline.{$icon} is missing");
    }
});
