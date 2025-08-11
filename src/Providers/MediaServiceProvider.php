<?php

namespace Nywerk\Media\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;
use Nywerk\Media\Middleware\MediaMiddleware;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'media');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/media-routes.php');

        $router = $this->app['router'];
        $router->aliasMiddleware('media', MediaMiddleware::class);

        Volt::mount(__DIR__ . '/../../resources/views/livewire');
    }
}
