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
        $randomName = Str::random() . '_' . $file['name'];
        $destinationPath = Auth::user()->selected_tenant_id . '/' . $randomName;

        $disk = config('media.disk');
        Storage::disk($disk)->put($destinationPath, file_get_contents($file['path']));

        $previewPath = $this->imagePreviewService->createPreviewForFile($file, $destinationPath);

        return Media::create([
            'tenant_id' => Auth::user()->selected_tenant_id,
            'path' => $destinationPath,
            'type' => 'image',
            'name' => $file['name'],
            'extension' => $file['extension'],
            'size' => $file['size'],
            'disk' => $disk,
            'ai_access' => true,
            'thumbnail' => $previewPath ?? null,
        ]);
    }

    /**
     * Store a Livewire TemporaryUploadedFile into medias and disk, and return the Media model.
     */
    public function storeFromUploadedFile($uploadedFile): Media
    {
        // Accept both Livewire TemporaryUploadedFile and Illuminate UploadedFile (testing)
        $originalName = $uploadedFile->getClientOriginalName();
        $extension = $uploadedFile->getClientOriginalExtension();
        $size = $uploadedFile->getSize();

        $randomName = Str::random() . '_' . $originalName;
        $destinationPath = Auth::user()->selected_tenant_id . '/' . $randomName;

        $disk = config('media.disk');
        $stream = fopen($uploadedFile->getRealPath(), 'r');
        Storage::disk($disk)->put($destinationPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $fileMeta = [
            'name' => $originalName,
            'extension' => $extension,
            'size' => $size,
        ];
        $previewPath = $this->imagePreviewService->createPreviewForFile($fileMeta, $destinationPath);

        return Media::create([
            'tenant_id' => Auth::user()->selected_tenant_id,
            'path' => $destinationPath,
            'type' => 'image',
            'name' => $originalName,
            'extension' => $extension,
            'size' => $size,
            'disk' => $disk,
            'ai_access' => true,
            'thumbnail' => $previewPath ?? null,
        ]);
    }

    /**
     * Convenience: return the public URL for a stored media path on images disk.
     */
    public function publicUrl(Media $media): string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk(config('media.disk'));

        return $disk->url($media->path);
    }
}


