<?php

namespace Noerd\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
use Noerd\Media\Services\ImageVariantService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaFileController extends Controller
{
    /**
     * Stream the original media file inline to the authenticated tenant user.
     */
    public function show(Media $media): StreamedResponse
    {
        $this->authorizeTenant($media);

        return Storage::disk($media->disk)->response($media->path);
    }

    /**
     * Stream the thumbnail (falling back to the original) inline.
     */
    public function thumbnail(Media $media): StreamedResponse
    {
        $this->authorizeTenant($media);

        return Storage::disk($media->disk)->response($media->thumbnail ?? $media->path);
    }

    /**
     * Stream a size-limited delivery variant to anyone holding a signed URL.
     * The lookup ignores the global scopes on purpose: the visitor is
     * anonymous, and a backend user of ANOTHER tenant browsing the website
     * must not lose the image to the tenant scope. A variant that cannot be
     * generated falls back to the original instead of a broken image.
     */
    public function image(int $mediaId, string $variant, ImageVariantService $variants): StreamedResponse
    {
        $media = Media::withoutGlobalScopes()->find($mediaId);

        abort_if($media === null || ! $variants->supports($media, $variant), 404);

        $disk = Storage::disk($media->disk);
        $path = $variants->pathFor($media, $variant) ?? $media->path;

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Ensure the media belongs to the current tenant. The BelongsToTenant
     * global scope already prevents cross-tenant route-model binding; this is
     * defense-in-depth and mirrors the established controller pattern.
     */
    private function authorizeTenant(Media $media): void
    {
        abort_unless($media->tenant_id === Auth::user()->selected_tenant_id, 404);
    }
}
