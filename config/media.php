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
    | media.file / media.thumbnail routes (tenant-scoped). In private mode the
    | public CMS/website embedding can no longer render media for anonymous
    | visitors.
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
        'svg',
        'avif',
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
];
