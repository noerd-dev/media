<?php

namespace Noerd\Media\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImagePreviewService
{
    public function createPreviewForFile(array $file, string $destinationPath): ?string
    {
        $manager = new ImageManager(new Driver());
        $disk = config('media.disk', 'images');
        $path = Storage::disk($disk)->path($destinationPath);

        $extension = strtolower($file['extension'] ?? '');
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
                putenv("PATH=/opt/homebrew/bin:" . getenv("PATH"));
            }

            Storage::disk($disk)->makeDirectory(Auth::user()->selected_tenant_id . '/thumbnails');

            $imagickClass = 'Imagick';
            if (class_exists($imagickClass)) {
                $imagick = new $imagickClass();
                $imagick->setOption('gs:MaxBitmap', '1000000000'); // Increase to 1GB
                $imagick->setResolution(150, 150);
                $imagick->readImage($fullPdfPath . '[0]');
                $imagick->setImageFormat('jpg');
                $imagick->writeImage($fullPreviewPath);
                $imagick->clear();
                $imagick->destroy();
            } else {
                // Imagick not available, skip PDF preview
                $thumbPath = null;
            }
        }

        return $thumbPath ?? null;
    }
}
