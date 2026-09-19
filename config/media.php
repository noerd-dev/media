<?php

return [
    'disk' => env('MEDIA_DISK', 'media'),

    /*
    |--------------------------------------------------------------------------
    | Private Media
    |--------------------------------------------------------------------------
    |
    | When false (default), media files live on the public "media" disk and are
    | served directly via the /storage/media symlink. When true, files are kept
    | outside the public path and are only reachable through the authenticated
    | media.file / media.thumbnail routes (tenant-scoped). Images a public
    | website embeds keep working: they are delivered through the signed
    | media.image route (see "Image Variants"). Files that cannot be scaled
    | (SVG, PDF) are not reachable for anonymous visitors in private mode.
    |
    */
    'private' => env('MEDIA_PRIVATE', false),

    /*
    |--------------------------------------------------------------------------
    | Allowed Upload Extensions
    |--------------------------------------------------------------------------
    |
    | The file extensions accepted by the media library upload dropzone. They
    | are validated server-side via Laravel's "mimes" rule. Adjust this list in
    | the project's published config/media.php to allow or restrict formats per
    | installation without touching the module.
    |
    */
    'allowed_extensions' => [
        'png',
        'jpg',
        'jpeg',
        'pdf',
        'txt',
        'webp',
        'avif',
        /*
         * 'svg' is deliberately NOT in this list. An SVG is a script-bearing
         * document, and the library serves files inline from the application's
         * own origin — so an uploaded SVG opened by a colleague runs in their
         * authenticated session. Re-add it only together with a sanitiser or a
         * separate asset domain. (The core's fallback resolver,
         * Noerd\Services\NullMediaResolver, refuses it for the same reason.)
         */
    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum Upload Size
    |--------------------------------------------------------------------------
    |
    | The maximum size (in kilobytes) accepted by the media library upload
    | dropzone, validated server-side via Laravel's "max" rule. Adjust this in
    | the project's published config/media.php to raise or lower the limit per
    | installation without touching the module.
    |
    */
    'max_upload_size' => 10420,

    /*
    |--------------------------------------------------------------------------
    | Ghostscript Binary
    |--------------------------------------------------------------------------
    |
    | Absolute path to the Ghostscript binary used to rasterize the first page
    | of an uploaded PDF into a JPG thumbnail. Leave empty to auto-detect "gs"
    | on $PATH (plus the usual Homebrew locations). PDF rasterization is
    | optional: without Ghostscript — and without the legacy imagick extension —
    | PDFs are simply stored without a thumbnail and the media library shows a
    | file-type tile instead.
    |
    */
    'ghostscript_binary' => env('MEDIA_GHOSTSCRIPT_BINARY'),

    /*
    |--------------------------------------------------------------------------
    | Image Variants
    |--------------------------------------------------------------------------
    |
    | Size-limited variants for DELIVERING an image to visitors (public
    | website, e-mail), name => maximum width in pixels. The original stays
    | untouched; a variant is generated on its first request, never upscaled,
    | encoded as WebP (JPEG/PNG when GD lacks WebP) and cached in the hidden
    | {tenant}/.variants directory. "web" is what MediaResolverContract::
    | getImageUrl() delivers by default. After changing a width run
    | "php artisan media:clear-variants" to drop the stale files.
    |
    | An image with more pixels than "variant_max_pixels" is delivered as the
    | original: GD decodes the whole bitmap into memory.
    |
    */
    'variants' => [
        'web' => 1920,
    ],

    'variant_quality' => 82,

    'variant_max_pixels' => 40_000_000,
];
