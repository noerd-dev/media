<?php

namespace Noerd\Media\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Noerd\Media\Models\Media;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ImagePreviewService
{
    /**
     * Supported extensions for thumbnail generation.
     */
    public const SUPPORTED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'pdf'];

    /**
     * Regenerate thumbnail for an existing media record.
     * This method works in console context by using the media's tenant_id.
     */
    public function regenerateThumbnail(Media $media): ?string
    {
        $disk = config('media.disk');
        $extension = mb_strtolower(pathinfo($media->path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::SUPPORTED_EXTENSIONS)) {
            return null;
        }

        $sourcePath = Storage::disk($disk)->path($media->path);

        if (! file_exists($sourcePath)) {
            return null;
        }

        // Delete existing thumbnail if present
        if ($media->thumbnail && Storage::disk($disk)->exists($media->thumbnail)) {
            Storage::disk($disk)->delete($media->thumbnail);
        }

        $manager = new ImageManager(new Driver());
        $thumbPath = null;

        if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp'])) {
            $image = $manager->read($sourcePath);

            $originalWidth = $image->width();
            $originalHeight = $image->height();

            $newWidth = 500;
            $newHeight = (int) (($originalHeight / $originalWidth) * $newWidth);

            $thumbnail = $image->resize($newWidth, $newHeight);

            $randomName = Str::random();
            $thumbPath = $media->tenant_id . '/thumbnails/thumb_' . $randomName . '.jpg';
            Storage::disk($disk)->put($thumbPath, (string) $thumbnail->toJpeg());
        }

        if ($extension === 'pdf') {
            $randomName = Str::random();
            $thumbPath = $media->tenant_id . '/thumbnails/pdf_' . $randomName . '.jpg';
            $fullPreviewPath = Storage::disk($disk)->path($thumbPath);

            Storage::disk($disk)->makeDirectory($media->tenant_id . '/thumbnails');

            if (! $this->generatePdfThumbnail($sourcePath, $fullPreviewPath)) {
                $thumbPath = null;
            }
        }

        return $thumbPath;
    }

    public function createPreviewForFile(array $file, string $destinationPath): ?string
    {
        $manager = new ImageManager(new Driver());
        $disk = config('media.disk');
        $path = Storage::disk($disk)->path($destinationPath);

        $extension = mb_strtolower($file['extension'] ?? '');
        if (in_array($extension, ['png', 'jpg', 'jpeg', 'webp'])) {
            $image = $manager->read($path);

            $originalWidth = $image->width();
            $originalHeight = $image->height();

            $newWidth = 500;
            $newHeight = (int) (($originalHeight / $originalWidth) * $newWidth);

            $thumbnail = $image->resize($newWidth, $newHeight);

            $randomName = Str::random();
            $thumbPath = Auth::user()->selected_tenant_id . '/thumbnails/thumb_' . $randomName . '.jpg';
            Storage::disk($disk)->put($thumbPath, (string) $thumbnail->toJpeg());
        }

        if ($extension === 'pdf') {
            $randomName = Str::random();
            // Store PDF previews alongside image thumbnails for consistency
            $thumbPath = Auth::user()->selected_tenant_id . '/thumbnails/pdf_' . $randomName . '.jpg';
            $fullPreviewPath = Storage::disk($disk)->path($thumbPath);

            Storage::disk($disk)->makeDirectory(Auth::user()->selected_tenant_id . '/thumbnails');

            if (! $this->generatePdfThumbnail($path, $fullPreviewPath)) {
                $thumbPath = null;
            }
        }

        return $thumbPath ?? null;
    }

    /**
     * Rasterize page 1 of a PDF to a JPG using pdftoppm (poppler-utils).
     *
     * Returns true on success. Logs a warning and returns false on any failure
     * so callers can decide to continue without a thumbnail.
     */
    private function generatePdfThumbnail(string $sourcePath, string $destinationPath): bool
    {
        // pdftoppm -singlefile appends ".jpg" itself — pass the prefix without extension.
        $outputPrefix = preg_replace('/\.jpe?g$/i', '', $destinationPath);

        $process = new Process([
            'pdftoppm',
            '-jpeg',
            '-r', '150',
            '-f', '1',
            '-l', '1',
            '-singlefile',
            $sourcePath,
            $outputPrefix,
        ]);

        // PHP-FPM / queue workers under Herd don't inherit Homebrew's PATH,
        // mirroring the previous workaround used with Imagick's Ghostscript delegate.
        if (app()->environment('local')) {
            $process->setEnv(['PATH' => '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin']);
        }

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            Log::warning('PDF thumbnail generation failed, continuing without thumbnail.', [
                'source' => $sourcePath,
                'command' => $process->getCommandLine(),
                'stderr' => $process->getErrorOutput(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return file_exists($destinationPath);
    }
}
