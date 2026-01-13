<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::prefix('media')
    ->as('media.')
    ->middleware(['auth', 'verified', 'web', 'app-access:media'])
    ->group(function (): void {
        Volt::route('/dashboard', 'media-list')->name('dashboard');
    });
