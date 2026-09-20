<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('registers the install and update commands', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('noerd:update-media');
    expect($commands)->toHaveKey('noerd:install-media');
});

it('publishes the module configs through the update command', function (): void {
    assertModuleUpdateCommandPublishesConfigs('noerd:update-media', dirname(__DIR__, 2), 'media');
});

it('patches the host files on update too and never resets an existing media config', function (): void {
    // A throwaway base path: the command writes into config/.
    $originalBasePath = app()->basePath();
    $host = storage_path('framework/testing/zz-media-update-' . getmypid());

    File::deleteDirectory($host);
    File::ensureDirectoryExists($host . '/config');
    File::put($host . '/config/noerd.php', "<?php\n\nreturn [];\n");
    File::put($host . '/config/media.php', "<?php\n\nreturn ['private' => true];\n");
    File::copy($originalBasePath . '/vendor/laravel/framework/config/filesystems.php', $host . '/config/filesystems.php');

    app()->setBasePath($host);

    try {
        $exit = Artisan::call('noerd:update-media', ['--force' => true, '--no-interaction' => true]);

        expect($exit)->toBe(0, Artisan::output())
            // An installation that predates the disk catches up through the update.
            ->and(File::get($host . '/config/filesystems.php'))->toContain("'media' => [")
            // --force refreshes YAML — it must never reset the host's settings.
            ->and(File::get($host . '/config/media.php'))->toContain("'private' => true");
    } finally {
        app()->setBasePath($originalBasePath);
        File::deleteDirectory($host);
    }
});
