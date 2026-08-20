<?php

namespace Noerd\Media\Tests\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Noerd\Media\Services\PdfThumbnailGenerator;

/**
 * Probes whether this host can actually rasterize a PDF. PdfThumbnailGenerator
 * ::isAvailable() is a best-effort check — imagick advertises PDF support even
 * when its Ghostscript delegate is unreachable — so integration tests decide
 * whether to skip by rendering a throwaway PDF once per process.
 */
class PdfRendering
{
    private static ?bool $isWorking = null;

    public static function isWorking(): bool
    {
        if (self::$isWorking !== null) {
            return self::$isWorking;
        }

        $source = tempnam(sys_get_temp_dir(), 'pdf-probe-') . '.pdf';
        $destination = tempnam(sys_get_temp_dir(), 'pdf-probe-') . '.jpg';

        file_put_contents($source, Pdf::loadHTML('<p>probe</p>')->output());

        self::$isWorking = app(PdfThumbnailGenerator::class)->generate($source, $destination);

        @unlink($source);
        @unlink($destination);

        return self::$isWorking;
    }
}
