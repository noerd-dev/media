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
];
