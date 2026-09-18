<?php

declare(strict_types=1);

namespace Noerd\Media\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Services\MediaPathService;
use Noerd\Models\Tenant;

/**
 * Drop the cached delivery variants. They are derived data: every variant is
 * generated again on its next request, so clearing is always safe — run it
 * after changing a width in `media.variants`, whose old files would otherwise
 * stay behind.
 *
 * Only the hidden variant directory of an existing tenant is touched.
 */
class MediaClearVariantsCommand extends Command
{
    protected $signature = 'media:clear-variants
                            {--tenant= : Restrict the run to one tenant id}';

    protected $description = 'Delete the cached image delivery variants';

    public function handle(MediaPathService $paths): int
    {
        $disk = Storage::disk((string) config('media.disk'));
        $cleared = 0;

        foreach ($this->tenantIds() as $tenantId) {
            $directory = $paths->variantDirectory($tenantId);

            if (! $disk->exists($directory)) {
                continue;
            }

            $cleared += count($disk->allFiles($directory));
            $disk->deleteDirectory($directory);
        }

        $this->info("Deleted {$cleared} cached variant(s).");

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
}
