<?php

declare(strict_types=1);

namespace Noerd\Media\Tests\Support;

use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;

/**
 * Real raster images on the faked media disk — a variant can only be proven
 * against bytes GD can actually decode.
 */
trait CreatesImageFixtures
{
    protected function zzImageBytes(int $width, int $height, string $format = 'jpg'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));

        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image);

        return (string) ob_get_clean();
    }

    protected function zzStoredImage(int $tenantId, string $name, int $width, int $height): Media
    {
        $media = Media::factory()->file($tenantId, $name)->create();

        Storage::disk('media')->put(
            $media->path,
            $this->zzImageBytes($width, $height, pathinfo($name, PATHINFO_EXTENSION)),
        );

        return $media;
    }
}
