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

        return Storage::disk($media->disk)->response(
            $media->path,
            null,
            $this->safeDeliveryHeaders($media),
        );
    }

    /**
     * Stream the thumbnail (falling back to the original) inline.
     */
    public function thumbnail(Media $media): StreamedResponse
    {
        $this->authorizeTenant($media);

        // Falls back to the original when no thumbnail exists, so it needs the
        // same delivery guard.
        return Storage::disk($media->disk)->response(
            $media->thumbnail ?? $media->path,
            null,
            $media->thumbnail !== null ? ['X-Content-Type-Options' => 'nosniff'] : $this->safeDeliveryHeaders($media),
        );
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

    /**
     * Headers that keep a stored file from becoming active content.
     *
     * The library streams from the application's OWN origin, so a document
     * that can carry script — an SVG, an HTML file — would run in the viewing
     * user's authenticated session. Uploads of those types are refused
     * (config('media.allowed_extensions')), but installations widen that list
     * and older libraries already hold such files, so delivery says no as
     * well: nosniff stops a mislabelled file from being re-interpreted, and
     * the script-bearing types are handed over as a download instead of being
     * rendered.
     *
     * @return array<string, string>
     */
    private function safeDeliveryHeaders(Media $media): array
    {
        $headers = ['X-Content-Type-Options' => 'nosniff'];

        $scriptBearing = ['svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'xsl', 'mhtml'];

        if (in_array(mb_strtolower((string) $media->extension), $scriptBearing, true)) {
            $headers['Content-Disposition'] = 'attachment; filename="' . addslashes((string) $media->name) . '"';
        }

        return $headers;
    }
}
