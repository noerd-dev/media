<?php

declare(strict_types=1);

namespace Noerd\Media\Support;

/**
 * The icon a file gets when it has no displayable preview — a PDF on an
 * installation without Ghostscript, an archive, a spreadsheet. One generic
 * document icon for every such file tells the reader nothing, so each family
 * of formats carries its own heroicon and colour.
 */
final class FileTypeIcon
{
    /**
     * Extension families mapped to their heroicon name and tile colours.
     *
     * The colour classes are written out in full: Tailwind scans the source for
     * literal class names and cannot generate them at runtime.
     *
     * @var array<string, array{icon: string, classes: string}>
     */
    private const TYPES = [
        'pdf' => ['icon' => 'document-text', 'classes' => 'bg-red-50 text-red-600'],

        'doc' => ['icon' => 'document-text', 'classes' => 'bg-blue-50 text-blue-600'],
        'docx' => ['icon' => 'document-text', 'classes' => 'bg-blue-50 text-blue-600'],
        'odt' => ['icon' => 'document-text', 'classes' => 'bg-blue-50 text-blue-600'],
        'rtf' => ['icon' => 'document-text', 'classes' => 'bg-blue-50 text-blue-600'],

        'xls' => ['icon' => 'table-cells', 'classes' => 'bg-green-50 text-green-700'],
        'xlsx' => ['icon' => 'table-cells', 'classes' => 'bg-green-50 text-green-700'],
        'csv' => ['icon' => 'table-cells', 'classes' => 'bg-green-50 text-green-700'],
        'ods' => ['icon' => 'table-cells', 'classes' => 'bg-green-50 text-green-700'],

        'ppt' => ['icon' => 'presentation-chart-bar', 'classes' => 'bg-orange-50 text-orange-600'],
        'pptx' => ['icon' => 'presentation-chart-bar', 'classes' => 'bg-orange-50 text-orange-600'],
        'odp' => ['icon' => 'presentation-chart-bar', 'classes' => 'bg-orange-50 text-orange-600'],

        'zip' => ['icon' => 'archive-box', 'classes' => 'bg-amber-50 text-amber-700'],
        'rar' => ['icon' => 'archive-box', 'classes' => 'bg-amber-50 text-amber-700'],
        '7z' => ['icon' => 'archive-box', 'classes' => 'bg-amber-50 text-amber-700'],
        'tar' => ['icon' => 'archive-box', 'classes' => 'bg-amber-50 text-amber-700'],
        'gz' => ['icon' => 'archive-box', 'classes' => 'bg-amber-50 text-amber-700'],

        'mp4' => ['icon' => 'film', 'classes' => 'bg-purple-50 text-purple-600'],
        'mov' => ['icon' => 'film', 'classes' => 'bg-purple-50 text-purple-600'],
        'avi' => ['icon' => 'film', 'classes' => 'bg-purple-50 text-purple-600'],
        'webm' => ['icon' => 'film', 'classes' => 'bg-purple-50 text-purple-600'],
        'mkv' => ['icon' => 'film', 'classes' => 'bg-purple-50 text-purple-600'],

        'mp3' => ['icon' => 'musical-note', 'classes' => 'bg-purple-50 text-purple-600'],
        'wav' => ['icon' => 'musical-note', 'classes' => 'bg-purple-50 text-purple-600'],
        'ogg' => ['icon' => 'musical-note', 'classes' => 'bg-purple-50 text-purple-600'],
        'm4a' => ['icon' => 'musical-note', 'classes' => 'bg-purple-50 text-purple-600'],

        'txt' => ['icon' => 'document-text', 'classes' => 'bg-gray-100 text-gray-500'],
        'md' => ['icon' => 'document-text', 'classes' => 'bg-gray-100 text-gray-500'],
    ];

    /**
     * The fallback for every extension the map does not know.
     *
     * @var array{icon: string, classes: string}
     */
    private const DEFAULT_TYPE = ['icon' => 'document', 'classes' => 'bg-gray-100 text-gray-500'];

    /**
     * The heroicon name (resolved as `heroicons::outline.{icon}`) and the tile
     * colour classes for a file extension, with or without a leading dot and in
     * any casing.
     *
     * @return array{icon: string, classes: string}
     */
    public static function for(string $extension): array
    {
        $normalized = mb_strtolower(mb_ltrim($extension, '.'));

        return self::TYPES[$normalized] ?? self::DEFAULT_TYPE;
    }
}
