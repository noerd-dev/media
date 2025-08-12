<?php

namespace Noerd\Media\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;
use Noerd\Media\Middleware\MediaMiddleware;
use Noerd\Media\Commands\NoerdMediaInstallCommand;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'media');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/media-routes.php');

        // Publish/merge configuration
        $this->mergeConfigFrom(__DIR__ . '/../../config/media.php', 'media');

        $router = $this->app['router'];
        $router->aliasMiddleware('media', MediaMiddleware::class);

        Volt::mount(__DIR__ . '/../../resources/views/livewire');

        if ($this->app->runningInConsole()) {
            $this->commands([
                NoerdMediaInstallCommand::class,
            ]);
        }
    }
}
