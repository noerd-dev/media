<?php

use Illuminate\Support\Facades\Route;


Route::prefix('media')
    ->as('media.')
    ->middleware(['auth', 'verified', 'web', 'app-access:media'])
    ->group(function (): void {
        Route::livewire('/dashboard', 'media-list')->name('dashboard');
    });
