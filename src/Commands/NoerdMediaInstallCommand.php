<?php

namespace Noerd\Media\Commands;

use Illuminate\Console\Command;
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

    protected function getSnippetTitle(): string
    {
        return 'Media';
    }

    protected function getSourceDir(): string
    {
        return dirname(__DIR__, 2) . '/app-contents/media';
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
