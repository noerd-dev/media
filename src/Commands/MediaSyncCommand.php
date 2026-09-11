<?php

declare(strict_types=1);

namespace Noerd\Media\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Exceptions\MediaInUseException;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Services\ImagePreviewService;
use Noerd\Media\Services\MediaMover;
use Noerd\Media\Services\MediaPathService;
use Noerd\Models\Tenant;

/**
 * Reconcile the media library with the disk in both directions: directories and
 * files put there by hand become folders and media rows, and rows whose file is
 * gone are reported (removed only with --prune).
 *
 * Only directories named after an existing tenant are scanned. The media disk
 * may hold content that does not belong to the library at all — CRM writes
 * `crm/print-mailings/…` there when configured — and that must stay untouched.
 */
class MediaSyncCommand extends Command
{
    protected $signature = 'media:sync
                            {--tenant= : Restrict the run to one tenant id}
                            {--prune : Delete media records whose file is missing}
                            {--dry-run : Report differences without writing anything}';

    protected $description = 'Reconcile the media library with the files on disk';

    private bool $dryRun = false;

    private int $imported = 0;

    private int $createdFolders = 0;

    private int $missing = 0;

    private int $pruned = 0;

    /** @var array<int, string> */
    private array $skipped = [];

    /** @var array<int, string> */
    private array $kept = [];

    /** Folders a dry run already reported, so a tree is not counted per file. */
    private array $plannedFolders = [];

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $disk = Storage::disk((string) config('media.disk'));
        $paths = app(MediaPathService::class);

        foreach ($this->tenantIds() as $tenantId) {
            $paths->forgetCache();

            $this->importDirectories($tenantId, $disk);
            $this->importFiles($tenantId, $disk);
            $this->reportMissing($tenantId, $disk);
        }

        return $this->summarize();
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

    /**
     * Every directory below the tenant root becomes a folder, so a hand-copied
     * tree shows up completely. Hidden directories (the generated thumbnails)
     * are skipped.
     */
    private function importDirectories(int $tenantId, Filesystem $disk): void
    {
        $directories = $disk->allDirectories((string) $tenantId);
        sort($directories);

        foreach ($directories as $directory) {
            $segments = $this->segmentsOf($tenantId, $directory);

            if ($segments === null) {
                continue;
            }

            $this->resolveFolder($tenantId, $segments);
        }
    }

    /**
     * Files without a matching media row are imported into the folder their
     * directory maps to.
     */
    private function importFiles(int $tenantId, Filesystem $disk): void
    {
        $known = Media::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('path')
            ->flip();

        $allowed = array_map('mb_strtolower', (array) config('media.allowed_extensions', []));

        foreach ($disk->allFiles((string) $tenantId) as $path) {
            if (isset($known[$path])) {
                continue;
            }

            $segments = $this->segmentsOf($tenantId, dirname($path) === (string) $tenantId ? '' : dirname($path));

            if ($segments === null) {
                continue;
            }

            $name = basename($path);
            $extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if ($allowed !== [] && ! in_array($extension, $allowed, true)) {
                $this->skipped[] = "{$path} (extension .{$extension} is not allowed)";

                continue;
            }

            $this->line("  + {$path}");
            $this->imported++;

            if ($this->dryRun) {
                continue;
            }

            $folder = $this->resolveFolder($tenantId, $segments);

            $media = Media::create([
                'tenant_id' => $tenantId,
                'folder_id' => $folder?->getKey(),
                'path' => $path,
                'type' => 'image',
                'name' => $name,
                'extension' => $extension === '' ? null : $extension,
                'size' => $disk->size($path),
                'disk' => (string) config('media.disk'),
            ]);

            $thumbnail = app(ImagePreviewService::class)->regenerateThumbnail($media);

            if ($thumbnail !== null) {
                $media->forceFill(['thumbnail' => $thumbnail])->save();
            }
        }
    }

    /**
     * Media rows whose file is gone. They are only listed; --prune deletes them
     * through the model, so the MediaUsageRegistry can still refuse a file
     * another module needs.
     */
    private function reportMissing(int $tenantId, Filesystem $disk): void
    {
        $mover = app(MediaMover::class);

        Media::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->chunkById(200, function ($files) use ($disk, $mover): void {
                foreach ($files as $media) {
                    if ($disk->exists((string) $media->path)) {
                        continue;
                    }

                    $this->missing++;
                    $this->line("  ! #{$media->id} {$media->name} ({$media->path})");

                    if (! $this->option('prune') || $this->dryRun) {
                        continue;
                    }

                    try {
                        $media->delete();
                    } catch (MediaInUseException $e) {
                        $this->kept[] = "#{$media->id} {$media->name}: {$e->reason}";

                        continue;
                    }

                    $mover->deleteFile($media);
                    $this->pruned++;
                }
            });
    }

    /**
     * The folder segments of a directory relative to the tenant root, or null
     * when the directory is a generated one the library does not own.
     *
     * @return array<int, string>|null
     */
    private function segmentsOf(int $tenantId, string $directory): ?array
    {
        if ($directory === '' || $directory === (string) $tenantId) {
            return [];
        }

        $relative = mb_substr($directory, mb_strlen((string) $tenantId) + 1);
        $segments = explode('/', $relative);

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.')) {
                return null;
            }
        }

        return $segments;
    }

    /**
     * Walk the segment chain, creating the folders that do not exist yet.
     *
     * @param  array<int, string>  $segments
     */
    private function resolveFolder(int $tenantId, array $segments): ?MediaFolder
    {
        $parent = null;
        $trail = '';

        foreach ($segments as $segment) {
            $trail = $trail === '' ? $segment : $trail . '/' . $segment;
            $parentId = $parent?->getKey();

            $folder = MediaFolder::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where(fn($query) => $parentId === null
                    ? $query->whereNull('parent_id')
                    : $query->where('parent_id', $parentId))
                ->where('path_segment', $segment)
                ->first();

            if (! $folder) {
                $key = $tenantId . '/' . $trail;

                if (! isset($this->plannedFolders[$key])) {
                    $this->plannedFolders[$key] = true;
                    $this->line("  + folder {$trail}");
                    $this->createdFolders++;
                }

                if ($this->dryRun) {
                    return null;
                }

                // The segment is already filesystem-safe, so it doubles as the
                // display name — an app folder is never created here, it is
                // found by its stored segment above.
                $folder = MediaFolder::create([
                    'tenant_id' => $tenantId,
                    'parent_id' => $parentId,
                    'name' => $segment,
                    'path_segment' => $segment,
                ]);
            }

            $parent = $folder;
        }

        return $parent;
    }

    private function summarize(): int
    {
        $this->newLine();
        $prefix = $this->dryRun ? 'Dry run: ' : '';

        $this->info("{$prefix}{$this->imported} file(s) imported, {$this->createdFolders} folder(s) created.");

        if ($this->missing > 0) {
            $this->warn("{$this->missing} media record(s) without a file.");

            if (! $this->option('prune')) {
                $this->line('  Remove them with --prune.');
            }
        }

        if ($this->pruned > 0) {
            $this->info("{$this->pruned} media record(s) deleted.");
        }

        foreach ($this->kept as $reason) {
            $this->warn("  Kept {$reason}");
        }

        foreach ($this->skipped as $skipped) {
            $this->warn("  Skipped {$skipped}");
        }

        return self::SUCCESS;
    }
}
