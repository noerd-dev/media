<?php

declare(strict_types=1);

namespace Noerd\Media\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Services\MediaMover;
use Noerd\Media\Services\MediaPathService;
use Noerd\Models\Tenant;

/**
 * One-time move of an installation from the historic flat layout
 * (`{tenant}/{random}_{name}`) to the folder-mirroring layout
 * (`{tenant}/{folders}/{name}`), thumbnails included.
 *
 * Runs headless: every query carries the tenant explicitly and drops the global
 * scopes, and only directories named after an existing tenant are touched — the
 * media disk may hold foreign content (CRM print mailings, for instance).
 */
class MediaRestructureCommand extends Command
{
    protected $signature = 'media:restructure
                            {--tenant= : Restrict the run to one tenant id}
                            {--dry-run : Show what would move without writing anything}';

    protected $description = 'Move media files into the folder structure of the media library';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $paths = app(MediaPathService::class);
        $mover = app(MediaMover::class);

        $movedFiles = 0;
        $movedThumbnails = 0;

        foreach ($this->tenantIds() as $tenantId) {
            $paths->forgetCache();

            $movedFiles += $this->restructureFiles($tenantId, $mover, $dryRun);
            $movedThumbnails += $this->relocateThumbnails($tenantId, $paths, $dryRun);

            if (! $dryRun) {
                // Prune first, then materialize every folder: an empty folder
                // must survive as a directory.
                $mover->pruneEmptyDirectories($tenantId);
                $this->ensureFolderDirectories($tenantId, $mover);
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run: {$movedFiles} file(s) and {$movedThumbnails} thumbnail(s) would move."
            : "Moved {$movedFiles} file(s) and {$movedThumbnails} thumbnail(s).");

        return self::SUCCESS;
    }

    /**
     * @return array<int, int>
     */
    private function tenantIds(): array
    {
        $option = $this->option('tenant');

        if ($option !== null) {
            return [(int) $option];
        }

        return Tenant::query()->orderBy('id')->pluck('id')->map(fn($id): int => (int) $id)->all();
    }

    private function restructureFiles(int $tenantId, MediaMover $mover, bool $dryRun): int
    {
        $moved = 0;

        Media::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->chunkById(200, function ($files) use ($mover, $dryRun, &$moved): void {
                foreach ($files as $media) {
                    $target = $mover->plan($media, $mover->folderOf($media));

                    if ($target['path'] === $media->path) {
                        continue;
                    }

                    $this->line("  {$media->path}");
                    $this->line("    -> {$target['path']}");

                    if (! $dryRun) {
                        $mover->relocate($media);
                    }

                    $moved++;
                }
            });

        return $moved;
    }

    /**
     * Thumbnails move from the visible `thumbnails/` directory into the hidden
     * `.thumbnails/`, so the reconciler never mistakes generated previews for
     * user content.
     */
    private function relocateThumbnails(int $tenantId, MediaPathService $paths, bool $dryRun): int
    {
        $disk = Storage::disk((string) config('media.disk'));
        $legacyDirectory = $tenantId . '/thumbnails';
        $targetDirectory = $paths->thumbnailDirectory($tenantId);
        $moved = 0;

        Media::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('thumbnail')
            ->where('thumbnail', 'like', $legacyDirectory . '/%')
            ->orderBy('id')
            ->chunkById(200, function ($files) use ($disk, $targetDirectory, $dryRun, &$moved): void {
                foreach ($files as $media) {
                    $target = $targetDirectory . '/' . basename((string) $media->thumbnail);

                    if (! $dryRun) {
                        if ($disk->exists($media->thumbnail)) {
                            $disk->makeDirectory($targetDirectory);
                            $disk->move($media->thumbnail, $target);
                        }

                        $media->forceFill(['thumbnail' => $target])->save();
                    }

                    $moved++;
                }
            });

        if (! $dryRun && $disk->files($legacyDirectory) === [] && $disk->directories($legacyDirectory) === []) {
            $disk->deleteDirectory($legacyDirectory);
        }

        return $moved;
    }

    /**
     * An empty folder is a directory too — the library and the disk show the
     * same tree.
     */
    private function ensureFolderDirectories(int $tenantId, MediaMover $mover): void
    {
        MediaFolder::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->each(fn(MediaFolder $folder) => $mover->ensureDirectory($tenantId, $folder));
    }
}
