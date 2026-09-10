<?php

namespace Noerd\Media\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Noerd\Events\TenantAppAssigned;
use Noerd\Media\Commands\MediaRelocateCommand;
use Noerd\Media\Commands\MediaUpdateCommand;
use Noerd\Media\Commands\NoerdMediaInstallCommand;
use Noerd\Media\Commands\RegenerateThumbnailsCommand;
use Noerd\Media\Listeners\EnsureAppFoldersOnAppAssignment;
use Noerd\Media\Services\AppFolderAccess;
use Noerd\Media\Services\AppFolderRegistry;
use Noerd\Media\Services\AppFolderService;
use Noerd\Media\Services\MediaResolver;
use Noerd\Media\Services\MediaUsageRegistry;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            \Noerd\Contracts\MediaResolverContract::class,
            MediaResolver::class,
        );

        // Modules register here which files they still need, so a deletion can
        // be refused without this module knowing who asked.
        $this->app->singleton(MediaUsageRegistry::class);

        // Folders an app owns in every tenant's library (a receipt inbox, say):
        // registered by the module, created per tenant, protected and shown
        // only to users who may use the app. The two scoped services memoize
        // per request.
        $this->app->singleton(AppFolderRegistry::class);
        $this->app->scoped(AppFolderService::class);
        $this->app->scoped(AppFolderAccess::class);
    }

    public function boot(): void
    {
        Event::listen(TenantAppAssigned::class, EnsureAppFoldersOnAppAssignment::class);

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
