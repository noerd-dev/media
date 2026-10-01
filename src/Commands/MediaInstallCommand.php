<?php

declare(strict_types=1);

namespace Noerd\Media\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Noerd\Media\Services\AppFolderService;
use Noerd\Traits\HasModuleInstallation;

class MediaInstallCommand extends Command
{
    use HasModuleInstallation;

    protected $signature = 'noerd:install-media
                            {--force : Overwrite existing files without asking}
                            {--migrate : Run migrations without asking (required to migrate in non-interactive runs)}
                            {--build : Run npm build without asking (required to build in non-interactive runs)}';

    protected $description = 'Install noerd media content and navigation';

    public function handle(): int
    {
        return $this->runModuleInstallation();
    }

    protected function getModuleName(): string
    {
        return 'Media';
    }

    protected function getModuleKey(): string
    {
        return 'media';
    }

    protected function getDefaultAppTitle(): string
    {
        return 'Media';
    }

    protected function getAppIcon(): string
    {
        return 'media::icons.app';
    }

    protected function getAppRoute(): string
    {
        return 'media.dashboard';
    }

    protected function getSourceDir(): string
    {
        return dirname(__DIR__, 2) . '/app-configs/media';
    }

    /**
     * Published so each project can toggle media.private and edit the allowed
     * extensions — an existing file is never touched by an update.
     *
     * @return array<int, string>
     */
    protected function getConfigFiles(): array
    {
        return ['media.php'];
    }

    /**
     * The `media` disk in the host's config/filesystems.php.
     */
    protected function publishModuleExtras(bool $update): void
    {
        $this->updateFilesystemsConfig();
    }

    /**
     * Every tenant gets the folders its apps registered (AppFolderRegistry) —
     * an existing installation catches up here when a module starts
     * registering one. The update command does not migrate, so an installation
     * that has not run the media migrations yet is told instead of failing.
     */
    protected function ensureModuleSetup(): void
    {
        if (! Schema::hasTable('media_folders') || ! Schema::hasColumn('media_folders', 'system_key')) {
            $this->warn('Run php artisan migrate to create the app folders in the media library.');

            return;
        }

        app(AppFolderService::class)->ensureForAllTenants();
        $this->line('<info>Ensured the app folders of every tenant in the media library.</info>');
    }

    /**
     * Update filesystems.php configuration to add media disk
     */
    private function updateFilesystemsConfig(): void
    {
        $filesystemsPath = base_path('config/filesystems.php');

        if (! file_exists($filesystemsPath)) {
            $this->warn('filesystems.php not found, skipping filesystem configuration.');

            return;
        }

        $filesystemsContent = (string) file_get_contents($filesystemsPath);

        // Check if media disk is already configured
        if (str_contains($filesystemsContent, "'media' =>")) {
            $this->line('<comment>Media disk already configured in filesystems.php.</comment>');

            return;
        }

        // Insert right after the opening of the 'disks' array: its closing
        // bracket cannot be found reliably, a comment after any disk would
        // place the media disk inside that disk.
        $pattern = "/('disks'\\s*=>\\s*\\[[^\\S\\n]*\\n)/";

        $mediaDiskConfig = "
        'media' => [
            'driver' => 'local',
            'root' => storage_path('app/public/media'),
            'url' => env('APP_URL') . '/storage/media',
            'visibility' => 'public',
            'throw' => false,
        ],
";

        $updatedContent = preg_replace($pattern, '$1' . $mediaDiskConfig, $filesystemsContent, 1);

        if ($updatedContent && $updatedContent !== $filesystemsContent) {
            file_put_contents($filesystemsPath, $updatedContent);
            $this->line('<info>Added media disk configuration to filesystems.php.</info>');
        } else {
            $this->warn('Could not automatically add media disk configuration. Please add it manually.');
        }
    }
}
