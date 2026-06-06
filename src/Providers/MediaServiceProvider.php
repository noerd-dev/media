<?php

namespace Noerd\Media\Providers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Noerd\Media\Commands\MediaRelocateCommand;
use Noerd\Media\Commands\MediaUpdateCommand;
use Noerd\Media\Commands\NoerdMediaInstallCommand;
use Noerd\Media\Commands\RegenerateThumbnailsCommand;
use Noerd\Media\Services\MediaResolver;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            \Noerd\Contracts\MediaResolverContract::class,
            MediaResolver::class,
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'media');
        Livewire::addNamespace('media', viewPath: __DIR__ . '/../../resources/views/components');
        Livewire::addLocation(viewPath: __DIR__ . '/../../resources/views/components');
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', 'media');
        $this->loadJsonTranslationsFrom(__DIR__ . '/../../resources/lang');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/media-routes.php');

        // Publish/merge configuration
        $this->mergeConfigFrom(__DIR__ . '/../../config/media.php', 'media');

        $this->configurePrivateDisk();

        $this->publishes([
            __DIR__ . '/../../config/media.php' => config_path('media.php'),
        ], 'media-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                NoerdMediaInstallCommand::class,
                MediaUpdateCommand::class,
                RegenerateThumbnailsCommand::class,
                MediaRelocateCommand::class,
            ]);
        }
    }

    /**
     * When media.private is enabled, relocate the "media" disk outside of the
     * public path so files are no longer reachable via the /storage symlink.
     * The url key is kept so URL generation does not throw; privacy comes from
     * the relocated root plus the authenticated media.file / media.thumbnail
     * routes.
     */
    private function configurePrivateDisk(): void
    {
        if (! config('media.private')) {
            return;
        }

        config(['filesystems.disks.media' => [
            'driver' => 'local',
            'root' => storage_path('app/media'),
            'url' => config('app.url') . '/storage/media',
            'visibility' => 'private',
            'throw' => false,
        ]]);

        Storage::forgetDisk('media');
    }
}
