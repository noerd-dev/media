<?php

use Illuminate\Support\Facades\Route;
use Noerd\Media\Http\Controllers\MediaFileController;

Route::prefix('media')
    ->as('media.')
    ->middleware(['auth', 'verified', 'web', 'app-access:media'])
    ->group(function (): void {
        Route::livewire('/dashboard', 'media::media-list')->name('dashboard');
    });

// Authenticated file streaming used by the private-media toggle. No
// app-access:media middleware: media previews are embedded across other apps
// (CRM, accounting, …), so any logged-in tenant user must be able to load them.
Route::prefix('media')
    ->as('media.')
    ->middleware(['web', 'auth', 'verified'])
    ->group(function (): void {
        Route::get('/file/{media}', [MediaFileController::class, 'show'])->name('file');
        Route::get('/thumb/{media}', [MediaFileController::class, 'thumbnail'])->name('thumbnail');
    });
