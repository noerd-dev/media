<?php

namespace Noerd\Media\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Noerd\Media\Services\AppFolderService;
use Noerd\Traits\HasModuleInstallation;
use Noerd\Traits\RequiresNoerdInstallation;

class NoerdMediaInstallCommand extends Command
{
    use HasModuleInstallation;
    use RequiresNoerdInstallation;

    protected $signature = 'noerd:install-media {--force : Overwrite existing files without asking}';

    protected $description = 'Install noerd media content and navigation';

    public function handle(): int
    {
        $this->updateFilesystemsConfig();
        $this->publishMediaConfig();

        $result = $this->runModuleInstallation();

        if ($result === self::SUCCESS) {
            $this->ensureAppFolders();
        }

        return $result;
    }

    /**
     * Every tenant gets the folders its apps registered (AppFolderRegistry) —
     * an existing installation catches up here when a module starts
     * registering one. The update command does not migrate, so an installation
     * that has not run the media migrations yet is told instead of failing.
     */
    protected function ensureAppFolders(): void
    {
        if (! Schema::hasTable('media_folders') || ! Schema::hasColumn('media_folders', 'system_key')) {
            $this->warn('Run php artisan migrate to create the app folders in the media library.');

            return;
        }

        app(AppFolderService::class)->ensureForAllTenants();
        $this->line('<info>Ensured the app folders of every tenant in the media library.</info>');
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

    protected function getSnippetTitle(): string
    {
        return 'Media';
    }

    protected function getSourceDir(): string
    {
        return dirname(__DIR__, 2) . '/app-configs/media';
    }

    /**
     * Publish the media config to the project root so each project can toggle
     * media.private. An existing config is left untouched to preserve the
     * project's choice.
     */
    private function publishMediaConfig(): void
    {
        $target = base_path('config/media.php');

        if (file_exists($target)) {
            $this->line('<comment>config/media.php already exists, leaving it untouched.</comment>');

            return;
        }

        copy(dirname(__DIR__, 2) . '/config/media.php', $target);
        $this->line('<info>Published config/media.php.</info>');
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

        $filesystemsContent = file_get_contents($filesystemsPath);

        // Check if media disk is already configured
        if (str_contains($filesystemsContent, "'media' =>")) {
            $this->line('<comment>Media disk already configured in filesystems.php.</comment>');

            return;
        }

        // Find the position to insert the media disk configuration
        // Look for the closing of the 'disks' array
        $pattern = '/(\s+)(],\s*\/\*[\s\S]*?Symbolic Links[\s\S]*?\*\/)/';

        $mediaDiskConfig = "
        'media' => [
            'driver' => 'local',
            'root' => storage_path('app/public/media'),
            'url' => env('APP_URL') . '/storage/media',
            'visibility' => 'public',
            'throw' => false,
        ],
";

        $replacement = $mediaDiskConfig . '$1$2';
        $updatedContent = preg_replace($pattern, $replacement, $filesystemsContent);

        if ($updatedContent && $updatedContent !== $filesystemsContent) {
            file_put_contents($filesystemsPath, $updatedContent);
            $this->line('<info>Added media disk configuration to filesystems.php.</info>');
        } else {
            $this->warn('Could not automatically add media disk configuration. Please add it manually.');
        }
    }
}
