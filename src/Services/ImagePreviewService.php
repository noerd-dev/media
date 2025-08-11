<?php

namespace Nywerk\Media\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImagePreviewService
{
    public function createPreviewForFile(array $file, string $destinationPath): ?string
    {
        $manager = new ImageManager(new Driver());
        $path = Storage::disk('images')->path($destinationPath);

        if (in_array($file['extension'], ['png', 'jpg', 'jepg'])) {
            $image = $manager->read($path);

            $originalWidth = $image->width();
            $originalHeight = $image->height();

            $newWidth = 500;
            $newHeight = (int) (($originalHeight / $originalWidth) * $newWidth);

            $thumbnail = $image->resize($newWidth, $newHeight);

            $randomName = Str::random();
            $thumbPath = auth()->user()->selected_tenant_id . '/thumbnails/thumb_' . $randomName;
            Storage::disk('images')->put($thumbPath, (string) $thumbnail->toJpeg());
        }

        if (in_array($file['extension'], ['pdf'])) {
            $filename = pathinfo($file['name'], PATHINFO_FILENAME);

            $previewName = "{$filename}.jpg";
            $thumbPath = "previews/{$previewName}";
            $fullPdfPath = $path;
            $fullPreviewPath = Storage::disk('images')->path($thumbPath);

            if (env('APP_ENV') === 'local') {
                putenv("PATH=/opt/homebrew/bin:" . getenv("PATH"));
            }

            Storage::disk('images')->makeDirectory('previews');

            $imagick = new Imagick();
            $imagick->setOption('gs:MaxBitmap', '1000000000'); // Increase to 1GB
            $imagick->setResolution(150, 150);
            $imagick->readImage($fullPdfPath . '[0]');
            $imagick->setImageFormat('jpg');
            $imagick->writeImage($fullPreviewPath);
            $imagick->clear();
            $imagick->destroy();
        }

        return $thumbPath ?? null;
    }
}
