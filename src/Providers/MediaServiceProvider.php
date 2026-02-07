<?php

namespace Noerd\Media\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Noerd\Media\Commands\NoerdMediaInstallCommand;
use Noerd\Media\Commands\RegenerateThumbnailsCommand;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'media');
        Livewire::addLocation(viewPath: __DIR__ . '/../../resources/views/components');
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', 'media');
        $this->loadJsonTranslationsFrom(__DIR__ . '/../../resources/lang');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/media-routes.php');

        // Publish/merge configuration
        $this->mergeConfigFrom(__DIR__ . '/../../config/media.php', 'media');

        if ($this->app->runningInConsole()) {
            $this->commands([
                NoerdMediaInstallCommand::class,
                RegenerateThumbnailsCommand::class,
            ]);
        }
    }
}
