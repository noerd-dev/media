<?php

namespace Noerd\Media\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Noerd\Media\Models\Media;

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

            if (env('APP_ENV') === 'local') {
                putenv('PATH=/opt/homebrew/bin:' . getenv('PATH'));
            }

            Storage::disk($disk)->makeDirectory($media->tenant_id . '/thumbnails');

            $imagickClass = 'Imagick';
            if (class_exists($imagickClass)) {
                try {
                    $imagick = new $imagickClass();
                    $imagick->setOption('gs:MaxBitmap', '1000000000');
                    $imagick->setResolution(150, 150);
                    $imagick->readImage($sourcePath . '[0]');
                    $imagick->setImageFormat('jpg');
                    $imagick->writeImage($fullPreviewPath);
                    $imagick->clear();
                    $imagick->destroy();
                } catch (\Throwable $e) {
                    Log::warning('PDF thumbnail regeneration failed, skipping preview.', [
                        'media_id' => $media->id,
                        'path' => $media->path,
                        'error' => $e->getMessage(),
                    ]);
                    $thumbPath = null;
                }
            } else {
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

        if (in_array($extension, ['pdf'])) {
            $filename = pathinfo($file['name'], PATHINFO_FILENAME);
            $randomName = Str::random();
            // Store PDF previews alongside image thumbnails for consistency
            $thumbPath = Auth::user()->selected_tenant_id . '/thumbnails/pdf_' . $randomName . '.jpg';
            $fullPdfPath = $path;
            $fullPreviewPath = Storage::disk($disk)->path($thumbPath);

            if (env('APP_ENV') === 'local') {
                putenv('PATH=/opt/homebrew/bin:' . getenv('PATH'));
            }

            Storage::disk($disk)->makeDirectory(Auth::user()->selected_tenant_id . '/thumbnails');

            $imagickClass = 'Imagick';
            if (class_exists($imagickClass)) {
                try {
                    $imagick = new $imagickClass();
                    $imagick->setOption('gs:MaxBitmap', '1000000000'); // Increase to 1GB
                    $imagick->setResolution(150, 150);
                    $imagick->readImage($fullPdfPath . '[0]');
                    $imagick->setImageFormat('jpg');
                    $imagick->writeImage($fullPreviewPath);
                    $imagick->clear();
                    $imagick->destroy();
                } catch (\Throwable $e) {
                    // Imagick may fail if the server policy blocks the PDF
                    // coder (common on Ubuntu/Debian default ImageMagick
                    // installs). In that case we still want the upload to
                    // succeed — just without a thumbnail.
                    Log::warning('PDF preview generation failed, upload continues without thumbnail.', [
                        'file' => $file['name'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                    $thumbPath = null;
                }
            } else {
                // Imagick not available, skip PDF preview
                $thumbPath = null;
            }
        }

        return $thumbPath ?? null;
    }
}
