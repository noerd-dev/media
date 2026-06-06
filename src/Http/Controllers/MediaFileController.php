<?php

namespace Noerd\Media\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Models\Media;
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
     * Ensure the media belongs to the current tenant. The BelongsToTenant
     * global scope already prevents cross-tenant route-model binding; this is
     * defense-in-depth and mirrors the established controller pattern.
     */
    private function authorizeTenant(Media $media): void
    {
        abort_unless($media->tenant_id === Auth::user()->selected_tenant_id, 404);
    }
}
