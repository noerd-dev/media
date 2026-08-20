<?php

namespace Noerd\Media\Services;

use Illuminate\Support\Facades\Log;
use Imagick;
use ImagickPixel;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Rasterizes the first page of a PDF into a JPG thumbnail.
 *
 * The generator tries Ghostscript first and only falls back to the imagick PHP
 * extension when it happens to be installed — imagick renders PDFs through the
 * very same Ghostscript delegate, so nothing is gained by requiring it. Neither
 * renderer is a hard dependency: when both are missing the generator reports
 * failure and the caller stores no thumbnail.
 */
class PdfThumbnailGenerator
{
    /**
     * Render resolution in DPI. Matches the previous imagick implementation.
     */
    private const RESOLUTION = 150;

    /**
     * Directories probed for the Ghostscript binary on top of $PATH. PHP-FPM
     * and queue workers under Herd don't inherit Homebrew's PATH.
     */
    private const EXTRA_BINARY_PATHS = ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin'];

    private bool $ghostscriptResolved = false;

    private ?string $ghostscriptBinary = null;

    /**
     * Render page 1 of $sourcePath into a JPG at $destinationPath.
     */
    public function generate(string $sourcePath, string $destinationPath): bool
    {
        $binary = $this->ghostscriptBinary();

        if ($binary !== null) {
            return $this->renderWithGhostscript($binary, $sourcePath, $destinationPath);
        }

        // imagick renders PDFs through the same Ghostscript delegate, so it is
        // only worth trying when no binary was found on the usual paths.
        if (extension_loaded('imagick')) {
            return $this->renderWithImagick($sourcePath, $destinationPath);
        }

        Log::warning('PDF thumbnail generation failed: no PDF renderer available — install Ghostscript (brew install ghostscript / apt-get install ghostscript) or point media.ghostscript_binary at it.');

        return false;
    }

    /**
     * Whether this installation ships a PDF renderer. Best effort: imagick
     * reports PDF support whenever it was built with the delegate, even when
     * the Ghostscript it shells out to cannot be located at runtime.
     */
    public function isAvailable(): bool
    {
        if ($this->ghostscriptBinary() !== null) {
            return true;
        }

        return extension_loaded('imagick') && Imagick::queryFormats('PDF') !== [];
    }

    /**
     * Resolve the Ghostscript binary from the config or the usual locations.
     */
    private function ghostscriptBinary(): ?string
    {
        if ($this->ghostscriptResolved) {
            return $this->ghostscriptBinary;
        }

        $this->ghostscriptResolved = true;

        $configured = config('media.ghostscript_binary');

        if (is_string($configured) && $configured !== '') {
            $this->ghostscriptBinary = is_executable($configured) ? $configured : null;

            return $this->ghostscriptBinary;
        }

        $this->ghostscriptBinary = (new ExecutableFinder())->find('gs', null, self::EXTRA_BINARY_PATHS);

        return $this->ghostscriptBinary;
    }

    private function renderWithGhostscript(string $binary, string $sourcePath, string $destinationPath): bool
    {
        $process = new Process([
            $binary,
            '-dQUIET',
            '-dSAFER',
            '-dBATCH',
            '-dNOPAUSE',
            '-dFirstPage=1',
            '-dLastPage=1',
            '-sDEVICE=jpeg',
            '-dJPEGQ=85',
            '-r' . self::RESOLUTION,
            '-dTextAlphaBits=4',
            '-dGraphicsAlphaBits=4',
            '-sOutputFile=' . $destinationPath,
            $sourcePath,
        ]);

        $process->setTimeout(60);

        try {
            $process->run();
        } catch (Throwable $e) {
            Log::warning('PDF thumbnail generation failed: ' . $e->getMessage());

            return false;
        }

        if (! $process->isSuccessful()) {
            Log::warning('PDF thumbnail generation failed: ' . mb_trim($process->getErrorOutput() ?: $process->getOutput()));
            @unlink($destinationPath);

            return false;
        }

        return $this->wroteUsableFile($destinationPath);
    }

    /**
     * Legacy path for installations that still ship the imagick extension.
     */
    private function renderWithImagick(string $sourcePath, string $destinationPath): bool
    {
        try {
            $imagick = new Imagick();

            // setResolution and setBackgroundColor must be set BEFORE readImage so
            // they apply to the Ghostscript render pass.
            $imagick->setResolution(self::RESOLUTION, self::RESOLUTION);
            $imagick->setBackgroundColor(new ImagickPixel('white'));
            $imagick->readImage($sourcePath . '[0]');
            $imagick->setImageBackgroundColor(new ImagickPixel('white'));
            $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $imagick->setImageFormat('jpeg');
            $imagick->writeImage($destinationPath);
            $imagick->clear();
        } catch (Throwable $e) {
            Log::warning('PDF thumbnail generation failed: ' . $e->getMessage());

            return false;
        }

        return $this->wroteUsableFile($destinationPath);
    }

    /**
     * Ghostscript exits successfully even for some unrenderable inputs, so an
     * empty output file counts as a failure.
     */
    private function wroteUsableFile(string $destinationPath): bool
    {
        if (! file_exists($destinationPath)) {
            return false;
        }

        if (filesize($destinationPath) > 0) {
            return true;
        }

        @unlink($destinationPath);

        return false;
    }
}
