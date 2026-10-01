<?php

namespace Noerd\Media\Services;

use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;

/**
 * The single place a media storage path is built.
 *
 * The disk mirrors the library: a file lives at
 * `{tenant_id}/{folder segments}/{name}`, so the storage root can be browsed,
 * backed up and filled by hand. Generated thumbnails stay flat in a reserved
 * `{tenant_id}/_thumbnails` directory, the size-limited delivery variants in
 * `{tenant_id}/_variants/{variant}` — they are derived data, not content, and
 * the reconciler skips them.
 *
 * The reserved directories are deliberately NOT dot directories: in public
 * mode the thumbnails are served straight from the /storage symlink, and the
 * stock nginx configuration of Forge (and most hosts) denies every path with a
 * dot segment (`location ~ /\.(?!well-known)`), so a `.thumbnails` URL answers
 * 403 in production while it works locally.
 */
class MediaPathService
{
    /**
     * The reserved per-tenant directory holding generated thumbnails.
     */
    public const THUMBNAIL_DIR = '_thumbnails';

    /**
     * The reserved per-tenant directory holding the generated delivery variants.
     */
    public const VARIANT_DIR = '_variants';

    /**
     * Longest folder or file segment written to disk.
     */
    private const MAX_SEGMENT_LENGTH = 100;

    /**
     * Guard against a corrupted parent chain: no library nests this deep.
     */
    private const MAX_DEPTH = 20;

    /**
     * Memoized folder id => segment chain, so a run over thousands of files
     * does not walk the same parent chain again and again.
     *
     * @var array<int, string>
     */
    private array $pathCache = [];

    /**
     * Turn a user-typed folder name into a filesystem-safe directory name.
     * Anything that could break out of the directory (separators, control
     * characters) or confuse the reconciler (a leading dot) is removed.
     */
    public function segmentFor(string $name): string
    {
        return $this->sanitize($name) ?: 'folder';
    }

    /**
     * A folder segment that no sibling of the same parent uses yet.
     */
    public function uniqueSegment(int $tenantId, ?int $parentId, string $name, ?int $ignoreFolderId = null): string
    {
        $base = $this->segmentFor($name);
        $candidate = $base;
        $suffix = 1;

        while (
            ($parentId === null && $this->isReservedDirectory($candidate))
            || $this->segmentTaken($tenantId, $parentId, $candidate, $ignoreFolderId)
        ) {
            $suffix++;
            $candidate = $base . '-' . $suffix;
        }

        return $candidate;
    }

    /**
     * The segment chain of a folder, without the tenant prefix
     * (e.g. `Rechnungen/2026`). An empty string for the tenant root.
     *
     * The parent chain is resolved WITHOUT global scopes: the reconciler and
     * the commands run headless, and an app folder hidden from the acting user
     * must still yield the path its files actually live at.
     */
    public function folderPath(?MediaFolder $folder): string
    {
        if (! $folder instanceof MediaFolder) {
            return '';
        }

        $key = (int) $folder->getKey();

        if (array_key_exists($key, $this->pathCache)) {
            return $this->pathCache[$key];
        }

        $segments = [];
        $node = $folder;
        $depth = 0;

        while ($node instanceof MediaFolder && $depth < self::MAX_DEPTH) {
            array_unshift($segments, $this->segmentOf($node));

            $parentId = $node->parent_id;
            $node = $parentId === null
                ? null
                : MediaFolder::withoutGlobalScopes()->find($parentId);
            $depth++;
        }

        return $this->pathCache[$key] = implode('/', $segments);
    }

    /**
     * The segment chain of a folder id, resolved scope-free.
     */
    public function folderPathById(?int $folderId): string
    {
        if ($folderId === null) {
            return '';
        }

        $folder = MediaFolder::withoutGlobalScopes()->find($folderId);

        return $this->folderPath($folder);
    }

    /**
     * Drop the memoized parent chains — after moving or renaming folders.
     */
    public function forgetCache(): void
    {
        $this->pathCache = [];
    }

    /**
     * The directory a folder's files live in, including the tenant prefix.
     */
    public function directoryFor(int $tenantId, ?MediaFolder $folder): string
    {
        $folderPath = $this->folderPath($folder);

        return $folderPath === '' ? (string) $tenantId : $tenantId . '/' . $folderPath;
    }

    /**
     * The full storage path of a file in a folder.
     */
    public function pathFor(int $tenantId, ?MediaFolder $folder, string $filename): string
    {
        return $this->directoryFor($tenantId, $folder) . '/' . $this->fileName($filename);
    }

    /**
     * A file name that is free in the target directory. `medias.name` IS the
     * basename on disk, so this keeps the database and the disk in step.
     *
     * The check runs against the target PATH — the place a file actually
     * occupies — not against the name. That keeps a bulk relocation stable: a
     * record that has not been moved yet still carries its old path and
     * therefore does not push the record being moved out of its own name.
     * The disk is consulted too, so a file dropped in by hand is never
     * overwritten by an upload.
     */
    public function uniqueFilename(int $tenantId, ?MediaFolder $folder, string $filename, ?int $ignoreMediaId = null): string
    {
        $sanitized = $this->fileName($filename);
        $extension = pathinfo($sanitized, PATHINFO_EXTENSION);
        $base = $extension === '' ? $sanitized : mb_substr($sanitized, 0, -(mb_strlen($extension) + 1));
        $base = $base === '' ? 'file' : $base;

        $directory = $this->directoryFor($tenantId, $folder);
        $candidate = $sanitized;
        $suffix = 1;

        while ($this->pathTaken($tenantId, $directory . '/' . $candidate, $ignoreMediaId)) {
            $suffix++;
            $candidate = $base . '-' . $suffix . ($extension === '' ? '' : '.' . $extension);
        }

        return $candidate;
    }

    /**
     * The reserved thumbnail directory of a tenant.
     */
    public function thumbnailDirectory(int $tenantId): string
    {
        return $tenantId . '/' . self::THUMBNAIL_DIR;
    }

    /**
     * The reserved directory holding a tenant's generated delivery variants.
     */
    public function variantDirectory(int $tenantId): string
    {
        return $tenantId . '/' . self::VARIANT_DIR;
    }

    /**
     * Where a delivery variant of a file is cached. The key is the media id —
     * moving or renaming the file keeps its variants — and the width is part
     * of the name, so a changed configuration never serves a stale size.
     */
    public function variantPath(Media $media, string $variant, int $width, string $extension): string
    {
        return $this->variantDirectory((int) $media->tenant_id)
            . '/' . $variant
            . '/' . $media->getKey() . '_' . $width . '.' . $extension;
    }

    /**
     * Whether a path lies inside one of the tenant's generated directories.
     */
    public function isGeneratedPath(int $tenantId, string $path): bool
    {
        foreach ([$this->thumbnailDirectory($tenantId), $this->variantDirectory($tenantId)] as $directory) {
            if ($path === $directory || str_starts_with($path, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a directory name directly below the tenant root is one the
     * library does not own: a generated directory, or any dot directory.
     */
    public function isReservedDirectory(string $name): bool
    {
        return str_starts_with($name, '.')
            || in_array($name, [self::THUMBNAIL_DIR, self::VARIANT_DIR], true);
    }

    /**
     * Make sure a folder carries a stored segment, computing one when the row
     * predates the column.
     */
    public function segmentOf(MediaFolder $folder): string
    {
        if (filled($folder->path_segment)) {
            return (string) $folder->path_segment;
        }

        return $this->segmentFor((string) $folder->name);
    }

    /**
     * Sanitize a file name, keeping its extension.
     */
    private function fileName(string $filename): string
    {
        $sanitized = $this->sanitize($filename);

        return $sanitized === '' ? 'file' : $sanitized;
    }

    /**
     * Strip everything a path segment must not contain: directory separators,
     * control characters, and leading dots or spaces.
     *
     * Umlauts and other UTF-8 characters are DELIBERATELY kept: the whole point
     * of mirroring the library onto the disk is that a person can read and fill
     * the tree, and a transliterated `Vertraege` next to a hand-made `Verträge`
     * directory would be two folders for one thing.
     */
    private function sanitize(string $value): string
    {
        $value = preg_replace('#[/\\\\:*?"<>|]+#', '', $value) ?? '';
        // Collapse whitespace FIRST: a tab is whitespace and a control
        // character, and stripping it before the collapse would glue two words
        // together.
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        $value = preg_replace('/[\x00-\x1F\x7F]+/', '', $value) ?? '';
        $value = mb_trim($value, " .\t\n\r\0\x0B");
        $value = mb_substr($value, 0, self::MAX_SEGMENT_LENGTH);

        return mb_trim($value, " .");
    }

    private function segmentTaken(int $tenantId, ?int $parentId, string $segment, ?int $ignoreFolderId): bool
    {
        return MediaFolder::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(fn($query) => $parentId === null
                ? $query->whereNull('parent_id')
                : $query->where('parent_id', $parentId))
            ->where('path_segment', $segment)
            ->when($ignoreFolderId !== null, fn($query) => $query->whereKeyNot($ignoreFolderId))
            ->exists();
    }

    private function pathTaken(int $tenantId, string $path, ?int $ignoreMediaId): bool
    {
        $claimed = Media::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('path', $path)
            ->when($ignoreMediaId !== null, fn($query) => $query->whereKeyNot($ignoreMediaId))
            ->exists();

        if ($claimed) {
            return true;
        }

        $ownedByThisRecord = $ignoreMediaId !== null && Media::withoutGlobalScopes()
            ->whereKey($ignoreMediaId)
            ->where('path', $path)
            ->exists();

        return ! $ownedByThisRecord && Storage::disk((string) config('media.disk'))->exists($path);
    }
}
