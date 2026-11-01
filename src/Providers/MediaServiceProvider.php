<?php

namespace Noerd\Media\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Noerd\Contracts\MediaResolverContract;
use Noerd\Events\TenantAppAssigned;
use Noerd\Media\Commands\MediaClearVariantsCommand;
use Noerd\Media\Commands\MediaInstallCommand;
use Noerd\Media\Commands\MediaRelocateCommand;
use Noerd\Media\Commands\MediaSyncCommand;
use Noerd\Media\Commands\MediaUpdateCommand;
use Noerd\Media\Commands\RegenerateThumbnailsCommand;
use Noerd\Media\Listeners\EnsureAppFoldersOnAppAssignment;
use Noerd\Media\Services\AppFolderAccess;
use Noerd\Media\Services\AppFolderRegistry;
use Noerd\Media\Services\AppFolderService;
use Noerd\Media\Services\MediaMover;
use Noerd\Media\Services\MediaPathService;
use Noerd\Media\Services\MediaResolver;
use Noerd\Media\Services\MediaUsageRegistry;
use Noerd\Providers\NoerdModuleServiceProvider;

class MediaServiceProvider extends NoerdModuleServiceProvider
{
    protected string $moduleKey = 'media';

    protected array $commands = [
        MediaInstallCommand::class,
        MediaUpdateCommand::class,
        RegenerateThumbnailsCommand::class,
        MediaRelocateCommand::class,
        MediaSyncCommand::class,
        MediaClearVariantsCommand::class,
    ];

    protected array $configs = ['media'];

    protected function registerModule(): void
    {
        $this->app->singleton(
            MediaResolverContract::class,
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

        // The disk mirrors the folder tree. Both services are request-scoped:
        // the path service memoizes parent chains, and every mover shares it.
        $this->app->scoped(MediaPathService::class);
        $this->app->scoped(MediaMover::class);
    }

    protected function bootModule(): void
    {
        Event::listen(TenantAppAssigned::class, EnsureAppFoldersOnAppAssignment::class);

        $this->configurePrivateDisk();
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
