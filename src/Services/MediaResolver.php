<?php

namespace Noerd\Media\Services;

use Illuminate\Support\Facades\Storage;
use Noerd\Contracts\MediaResolverContract;
use Noerd\Media\Models\Media;

class MediaResolver implements MediaResolverContract
{
    public function __construct(private readonly MediaUploadService $uploadService) {}

    public function getPreviewUrl(int $mediaId): ?string
    {
        $media = Media::find($mediaId);

        if (! $media) {
            return null;
        }

        return $media->thumbnailUrl();
    }

    public function exists(int $mediaId): bool
    {
        return Media::where('id', $mediaId)->exists();
    }

    public function getRelativeUrl(int $mediaId): ?string
    {
        $media = Media::find($mediaId);

        if (! $media) {
            return null;
        }

        $url = Storage::disk($media->disk)->url($media->path);

        return mb_strstr($url, '/storage');
    }

    public function storeUploadedFile(mixed $uploadedFile): ?string
    {
        $media = $this->uploadService->storeFromUploadedFile($uploadedFile);

        $url = Storage::disk($media->disk)->url($media->path);

        return mb_strstr($url, '/storage');
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
