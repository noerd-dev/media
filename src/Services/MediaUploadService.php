<?php

namespace Noerd\Media\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Noerd\Media\Models\Media;

class MediaUploadService
{
    public function __construct(private readonly ImagePreviewService $imagePreviewService) {}

    /**
     * Store a file described by an array (dropzone-style) into medias and disk, and return the Media model.
     * Expected keys: name, extension, size, path
     */
    public function storeFromArray(array $file): Media
    {
        $sanitizedName = $this->sanitizeFilename($file['name']);
        $randomName = Str::random() . '_' . $sanitizedName;
        $destinationPath = Auth::user()->selected_tenant_id . '/' . $randomName;

        $disk = config('media.disk');
        Storage::disk($disk)->put($destinationPath, file_get_contents($file['path']));

        $previewPath = $this->imagePreviewService->createPreviewForFile($file, $destinationPath);

        return Media::create([
            'tenant_id' => Auth::user()->selected_tenant_id,
            'path' => $destinationPath,
            'type' => 'image',
            'name' => $sanitizedName,
            'extension' => $file['extension'],
            'size' => $file['size'],
            'disk' => $disk,
            'thumbnail' => $previewPath ?? null,
        ]);
    }

    public function storeFromUploadedFile($uploadedFile): Media
    {
        $originalName = $uploadedFile->getClientOriginalName();
        $sanitizedName = $this->sanitizeFilename($originalName);
        $extension = $uploadedFile->getClientOriginalExtension();
        $size = $uploadedFile->getSize();

        $randomName = Str::random() . '_' . $sanitizedName;
        $destinationPath = Auth::user()->selected_tenant_id . '/' . $randomName;

        $disk = config('media.disk');
        $stream = fopen($uploadedFile->getRealPath(), 'r');
        Storage::disk($disk)->put($destinationPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $fileMeta = [
            'name' => $sanitizedName,
            'extension' => $extension,
            'size' => $size,
        ];
        $previewPath = $this->imagePreviewService->createPreviewForFile($fileMeta, $destinationPath);

        return Media::create([
            'tenant_id' => Auth::user()->selected_tenant_id,
            'path' => $destinationPath,
            'type' => 'image',
            'name' => $sanitizedName,
            'extension' => $extension,
            'size' => $size,
            'disk' => $disk,
            'thumbnail' => $previewPath ?? null,
        ]);
    }

    /**
     * Convenience: return the URL for a stored media file, honoring the
     * private-media toggle (direct /storage URL in public mode, authenticated
     * route in private mode).
     */
    public function publicUrl(Media $media): string
    {
        return $media->url();
    }

    private function sanitizeFilename(string $filename): string
    {
        return Str::ascii($filename, 'de');
    }
}
