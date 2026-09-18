<?php

namespace Noerd\Media\Services;

use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;

/**
 * Every folder change moves the bytes with it.
 *
 * This is deliberately a service and not a model observer: the library moves
 * files with mass `update()` queries, which fire no model events. Each call
 * site asks the mover explicitly, so no path can drift out of sync.
 */
class MediaMover
{
    public function __construct(
        private readonly MediaPathService $paths,
        private readonly ImageVariantService $variants,
    ) {}

    /**
     * Move a file into a folder (null = the tenant root), renaming it when the
     * target folder already holds a file of that name.
     */
    public function moveToFolder(Media $media, ?MediaFolder $target): Media
    {
        ['name' => $filename, 'path' => $newPath] = $this->plan($media, $target);

        $this->moveFile($media, $newPath);

        $media->forceFill([
            'folder_id' => $target?->getKey(),
            'name' => $filename,
            'path' => $newPath,
        ])->save();

        return $media;
    }

    /**
     * Where a file would end up, without touching anything — the dry run of
     * the restructure command reports from this.
     *
     * @return array{name: string, path: string}
     */
    public function plan(Media $media, ?MediaFolder $target): array
    {
        $tenantId = (int) $media->tenant_id;

        $filename = $this->paths->uniqueFilename(
            $tenantId,
            $target,
            $this->basenameFor($media),
            (int) $media->getKey(),
        );

        return [
            'name' => $filename,
            'path' => $this->paths->pathFor($tenantId, $target, $filename),
        ];
    }

    /**
     * The folder a media row belongs to, resolved scope-free.
     */
    public function folderOf(Media $media): ?MediaFolder
    {
        return $media->folder_id === null
            ? null
            : MediaFolder::withoutGlobalScopes()->find((int) $media->folder_id);
    }

    /**
     * Recompute a file's path from the folder it currently belongs to and move
     * it there. Used by the reconciler and after a folder was renamed or moved.
     */
    public function relocate(Media $media): Media
    {
        return $this->moveToFolder($media, $this->folderOf($media));
    }

    /**
     * After a folder was renamed or re-parented: move every file underneath it
     * to its new location. The folder row already carries the new state, so the
     * new path is derived and the old one is read from each media row.
     */
    public function relocateFolder(MediaFolder $folder): void
    {
        $this->paths->forgetCache();

        $folderIds = $this->subtreeIds($folder);

        Media::withoutGlobalScopes()
            ->where('tenant_id', $folder->tenant_id)
            ->whereIn('folder_id', $folderIds)
            ->orderBy('id')
            ->chunkById(200, function ($files): void {
                foreach ($files as $media) {
                    $this->relocate($media);
                }
            });

        $this->pruneEmptyDirectories((int) $folder->tenant_id);
    }

    /**
     * Delete a file with everything generated from it: the thumbnail and the
     * cached delivery variants.
     */
    public function deleteFile(Media $media): void
    {
        $disk = Storage::disk($media->disk);

        if ($media->path && $disk->exists($media->path)) {
            $disk->delete($media->path);
        }

        if ($media->thumbnail && $disk->exists($media->thumbnail)) {
            $disk->delete($media->thumbnail);
        }

        $this->variants->forget($media);
    }

    /**
     * Create the directory of a folder, so an empty folder is visible on disk.
     */
    public function ensureDirectory(int $tenantId, ?MediaFolder $folder): void
    {
        Storage::disk($this->disk())->makeDirectory($this->paths->directoryFor($tenantId, $folder));
    }

    /**
     * Remove a folder's directory once its files have been moved away.
     */
    public function removeDirectory(int $tenantId, ?MediaFolder $folder): void
    {
        if (! $folder instanceof MediaFolder) {
            return;
        }

        Storage::disk($this->disk())->deleteDirectory($this->paths->directoryFor($tenantId, $folder));
    }

    /**
     * Drop directories that hold neither files nor sub-directories any more,
     * deepest first. The tenant root and the hidden generated directories stay.
     */
    public function pruneEmptyDirectories(int $tenantId): void
    {
        $disk = Storage::disk($this->disk());
        $directories = $disk->allDirectories((string) $tenantId);

        usort($directories, fn(string $a, string $b): int => mb_substr_count($b, '/') <=> mb_substr_count($a, '/'));

        foreach ($directories as $directory) {
            if ($this->paths->isGeneratedPath($tenantId, $directory)) {
                continue;
            }

            if ($disk->files($directory) === [] && $disk->directories($directory) === []) {
                $disk->deleteDirectory($directory);
            }
        }
    }

    /**
     * The file name a record must carry on disk.
     *
     * `medias.name` is not reliably a full file name: rows written before the
     * disk mirrored the library often hold the name WITHOUT its extension while
     * the path has it. Taking the name verbatim would strip the extension off
     * the file, so the known extension is appended when it is missing.
     */
    private function basenameFor(Media $media): string
    {
        $name = (string) $media->name;
        $extension = (string) ($media->extension ?: pathinfo((string) $media->path, PATHINFO_EXTENSION));

        if ($extension === '' || $name === '') {
            return $name;
        }

        if (mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)) === mb_strtolower($extension)) {
            return $name;
        }

        return $name . '.' . $extension;
    }

    /**
     * Move the bytes, leaving the model untouched. A missing source is not an
     * error: the reconciler reports those rows separately.
     */
    private function moveFile(Media $media, string $newPath): void
    {
        $currentPath = (string) $media->path;

        if ($currentPath === $newPath) {
            return;
        }

        $disk = Storage::disk($media->disk);

        if (! $disk->exists($currentPath)) {
            return;
        }

        $disk->makeDirectory(dirname($newPath));
        $disk->move($currentPath, $newPath);
    }

    /**
     * The folder and every folder below it.
     *
     * @return array<int, int>
     */
    private function subtreeIds(MediaFolder $folder): array
    {
        $ids = [(int) $folder->getKey()];
        $frontier = $ids;

        while ($frontier !== []) {
            $children = MediaFolder::withoutGlobalScopes()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn($id): int => (int) $id)
                ->all();

            $frontier = array_values(array_diff($children, $ids));
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    private function disk(): string
    {
        return (string) config('media.disk');
    }
}
