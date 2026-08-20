<?php

namespace Noerd\Media\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

use function Laravel\Prompts\progress;

use Noerd\Media\Models\Media;
use Noerd\Media\Services\ImagePreviewService;
use Noerd\Media\Services\PdfThumbnailGenerator;

class RegenerateThumbnailsCommand extends Command
{
    protected $signature = 'media:regenerate-thumbnails
                            {--missing : Only regenerate missing thumbnails (default)}
                            {--all : Regenerate all thumbnails}
                            {--id= : Regenerate thumbnail for a specific media ID}';

    protected $description = 'Regenerate thumbnails for media files';

    public function __construct(
        protected ImagePreviewService $imagePreviewService,
        protected PdfThumbnailGenerator $pdfThumbnailGenerator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->pdfThumbnailGenerator->isAvailable()) {
            $this->warn('No PDF renderer available — PDFs will be left without a thumbnail. Install Ghostscript (brew install ghostscript) or set MEDIA_GHOSTSCRIPT_BINARY.');
        }

        $disk = config('media.disk');
        $regenerated = 0;
        $skipped = 0;
        $failed = 0;

        if ($this->option('id')) {
            return $this->regenerateSingle((int) $this->option('id'));
        }

        $query = Media::query()
            ->whereNotNull('path')
            ->where(function ($q): void {
                foreach (ImagePreviewService::SUPPORTED_EXTENSIONS as $ext) {
                    $q->orWhere('path', 'like', '%.' . $ext);
                }
            });

        $mediaItems = $query->get();

        if ($mediaItems->isEmpty()) {
            $this->info('No media files found to process.');

            return self::SUCCESS;
        }

        $this->info('Processing ' . $mediaItems->count() . ' media files...');

        $regenerateAll = $this->option('all');

        progress(
            label: 'Regenerating thumbnails',
            steps: $mediaItems,
            callback: function (Media $media) use ($disk, $regenerateAll, &$regenerated, &$skipped, &$failed): void {
                $thumbnailExists = $media->thumbnail && Storage::disk($disk)->exists($media->thumbnail);

                if (! $regenerateAll && $thumbnailExists) {
                    $skipped++;

                    return;
                }

                $newThumbPath = $this->imagePreviewService->regenerateThumbnail($media);

                if ($newThumbPath) {
                    $media->update(['thumbnail' => $newThumbPath]);
                    $regenerated++;
                } else {
                    $failed++;
                }
            },
        );

        $this->newLine();
        $this->info("Regenerated: {$regenerated}");
        $this->info("Skipped (existing): {$skipped}");

        if ($failed > 0) {
            $this->warn("Failed: {$failed}");
        }

        return self::SUCCESS;
    }

    protected function regenerateSingle(int $id): int
    {
        $media = Media::find($id);

        if (! $media) {
            $this->error("Media with ID {$id} not found.");

            return self::FAILURE;
        }

        $this->info("Regenerating thumbnail for media ID {$id}: {$media->name}");

        $newThumbPath = $this->imagePreviewService->regenerateThumbnail($media);

        if ($newThumbPath) {
            $media->update(['thumbnail' => $newThumbPath]);
            $this->info("Thumbnail regenerated: {$newThumbPath}");

            return self::SUCCESS;
        }

        $this->error('Failed to regenerate thumbnail. Source file may not exist or format not supported.');

        return self::FAILURE;
    }
}
