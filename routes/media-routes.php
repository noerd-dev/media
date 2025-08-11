<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::prefix('media')
    ->as('media.')
    ->middleware(['auth', 'verified', 'web', 'media'])
    ->group(function (): void {
        Volt::route('/dashboard', 'media-table')->name('dashboard');
    });
