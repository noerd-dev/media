<?php

namespace Noerd\Media\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;

class MediaUploadService
{
    public function __construct(
        private readonly ImagePreviewService $imagePreviewService,
        private readonly MediaPathService $paths,
    ) {}

    /**
     * Store a file described by an array (dropzone-style) into medias and disk, and return the Media model.
     * Expected keys: name, extension, size, path
     *
     * The target folder is passed in rather than applied afterwards: the disk
     * mirrors the library, so the folder decides where the bytes go.
     */
    public function storeFromArray(array $file, ?int $folderId = null): Media
    {
        $tenantId = (int) Auth::user()->selected_tenant_id;
        $folder = $this->resolveFolder($folderId);

        $name = $this->paths->uniqueFilename($tenantId, $folder, (string) $file['name']);
        $destinationPath = $this->paths->pathFor($tenantId, $folder, $name);

        $disk = config('media.disk');
        Storage::disk($disk)->put($destinationPath, file_get_contents($file['path']));

        $previewPath = $this->imagePreviewService->createPreviewForFile($file, $destinationPath);

        return Media::create([
            'tenant_id' => $tenantId,
            'folder_id' => $folderId,
            'path' => $destinationPath,
            'type' => 'image',
            'name' => $name,
            'extension' => $file['extension'],
            'size' => $file['size'],
            'disk' => $disk,
            'thumbnail' => $previewPath ?? null,
        ]);
    }

    public function storeFromUploadedFile($uploadedFile, ?int $folderId = null): Media
    {
        $tenantId = (int) Auth::user()->selected_tenant_id;
        $folder = $this->resolveFolder($folderId);

        $extension = $uploadedFile->getClientOriginalExtension();
        $size = $uploadedFile->getSize();

        $name = $this->paths->uniqueFilename($tenantId, $folder, $uploadedFile->getClientOriginalName());
        $destinationPath = $this->paths->pathFor($tenantId, $folder, $name);

        $disk = config('media.disk');
        $stream = fopen($uploadedFile->getRealPath(), 'r');
        Storage::disk($disk)->put($destinationPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $fileMeta = [
            'name' => $name,
            'extension' => $extension,
            'size' => $size,
        ];
        $previewPath = $this->imagePreviewService->createPreviewForFile($fileMeta, $destinationPath);

        return Media::create([
            'tenant_id' => $tenantId,
            'folder_id' => $folderId,
            'path' => $destinationPath,
            'type' => 'image',
            'name' => $name,
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

    /**
     * The upload target may be an app folder the acting user cannot see in the
     * library; resolve it scope-free and let the screen decide accessibility.
     */
    private function resolveFolder(?int $folderId): ?MediaFolder
    {
        return $folderId === null
            ? null
            : MediaFolder::withoutGlobalScopes()->find($folderId);
    }
}
