<?php

namespace Noerd\Media\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Noerd\Media\Models\Media;
use Throwable;

/**
 * Size-limited DELIVERY variants of an image.
 *
 * The original is stored untouched — a receipt photo or an archive scan must
 * keep every pixel — but a visitor of a public page should never download it
 * in full. A variant is generated on its first request, never upscaled, and
 * cached in the hidden `{tenant_id}/.variants/{variant}` directory; from then
 * on it is only streamed.
 *
 * The variant names and their maximum widths are configuration
 * (`media.variants`). The thumbnail of the library is a different thing: it is
 * a fixed preview tile, see ImagePreviewService.
 */
class ImageVariantService
{
    /**
     * Source formats GD can scale. Everything else (SVG, GIF, AVIF, PDF, …) is
     * delivered as the original.
     */
    public const SUPPORTED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * The formats a variant may have been encoded in, newest preference first.
     */
    private const OUTPUT_EXTENSIONS = ['webp', 'png', 'jpg'];

    public function __construct(private readonly MediaPathService $paths) {}

    /**
     * The configured variants, name => maximum width in pixels.
     *
     * @return array<string, int>
     */
    public function variants(): array
    {
        $variants = [];

        foreach ((array) config('media.variants', []) as $name => $width) {
            if (is_string($name) && preg_match('/^[a-z0-9_-]+$/', $name) === 1 && (int) $width > 0) {
                $variants[$name] = (int) $width;
            }
        }

        return $variants;
    }

    /**
     * Whether a variant of this file can be generated at all.
     */
    public function supports(Media $media, string $variant): bool
    {
        return isset($this->variants()[$variant])
            && in_array($media->normalizedExtension(), self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * The cached variant of a file, generated on the first call. Null means
     * "deliver the original": the file cannot be scaled, is too large for GD
     * or could not be read — a broken variant must never break the page.
     */
    public function pathFor(Media $media, string $variant): ?string
    {
        if (! $this->supports($media, $variant)) {
            return null;
        }

        $width = $this->variants()[$variant];
        $path = $this->paths->variantPath($media, $variant, $width, $this->outputExtension($media));
        $disk = Storage::disk($media->disk);

        if ($disk->exists($path)) {
            return $path;
        }

        try {
            $encoded = $this->encode($media, $width);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if ($encoded === null) {
            return null;
        }

        $disk->put($path, $encoded);

        return $path;
    }

    /**
     * Drop the cached variants of a file. Variants written under a width that
     * is no longer configured are left to `media:clear-variants`.
     */
    public function forget(Media $media): void
    {
        $paths = [];

        foreach ($this->variants() as $variant => $width) {
            foreach (self::OUTPUT_EXTENSIONS as $extension) {
                $paths[] = $this->paths->variantPath($media, $variant, $width, $extension);
            }
        }

        if ($paths !== []) {
            Storage::disk($media->disk)->delete($paths);
        }
    }

    /**
     * The scaled image, or null when the source is missing or exceeds the
     * pixel budget — GD holds the decoded bitmap in memory, and a fatal
     * out-of-memory error cannot be caught.
     */
    private function encode(Media $media, int $width): ?string
    {
        $bytes = Storage::disk($media->disk)->get((string) $media->path);

        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        $size = getimagesizefromstring($bytes);

        if ($size === false || $size[0] * $size[1] > (int) config('media.variant_max_pixels', 40_000_000)) {
            return null;
        }

        $image = (new ImageManager(new Driver()))->read($bytes)->scaleDown(width: $width);
        $quality = (int) config('media.variant_quality', 82);

        return match ($this->outputExtension($media)) {
            'webp' => (string) $image->toWebp($quality),
            'png' => (string) $image->toPng(),
            default => (string) $image->toJpeg($quality),
        };
    }

    /**
     * WebP keeps transparency and is the smallest; a GD build without it falls
     * back to PNG for a PNG source (alpha channel) and to JPEG otherwise.
     */
    private function outputExtension(Media $media): string
    {
        if (function_exists('imagewebp')) {
            return 'webp';
        }

        return $media->normalizedExtension() === 'png' ? 'png' : 'jpg';
    }
}
