<?php

namespace Noerd\Media\Commands;

use Exception;
use Illuminate\Console\Command;
use Noerd\Noerd\Models\TenantApp;
use Noerd\Noerd\Traits\RequiresNoerdInstallation;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class NoerdMediaInstallCommand extends Command
{
    use RequiresNoerdInstallation;

    protected $signature = 'noerd:install-media {--force : Overwrite existing files without asking}';

    protected $description = 'Install noerd media content to the local content directory';

    private array $results = [
        'created_dirs' => 0,
        'copied_files' => 0,
        'skipped_files' => 0,
        'overwritten_files' => 0,
    ];

    private ?string $installedAppKey = null;

    public function handle()
    {
        // Ensure noerd:install has been run first
        if (! $this->ensureNoerdInstalled()) {
            return 1;
        }

        $this->info('Installing noerd media content...');
        $this->line('');

        $sourceDir = base_path('vendor/noerd/media/content');
        $targetDir = base_path('content');

        if (! is_dir($sourceDir)) {
            $this->error("Source directory not found: {$sourceDir}");

            return 1;
        }

        // Create target directory if it doesn't exist
        if (! is_dir($targetDir)) {
            if (! mkdir($targetDir, 0755, true)) {
                $this->error("Failed to create target directory: {$targetDir}");

                return 1;
            }
            $this->info("Created target directory: {$targetDir}");
        }

        try {
            $this->copyDirectoryContents($sourceDir, $targetDir);

            // Install as new app
            $this->installAsNewApp();

            $this->displaySummary();

            $this->line('');
            $this->info('Noerd Media successfully installed!');

            // Ask to assign app to tenant (only if a new app was created)
            if ($this->installedAppKey) {
                $this->line('');
                if ($this->confirm('Would you like to assign the app to tenants now?', true)) {
                    $this->assignAppToTenants($this->installedAppKey);
                }
            }

            return 0;
        } catch (Exception $e) {
            $this->error('Error installing noerd media content: ' . $e->getMessage());

            return 1;
        }
    }

    private function installAsNewApp(): void
    {
        $this->line('');
        $this->info('New app configuration:');

        $appTitle = $this->ask('App name', 'Media');

        // Automatically derive key from name (replace umlauts, uppercase)
        $appKey = mb_strtoupper(str_replace(
            ['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü', ' '],
            ['AE', 'OE', 'UE', 'SS', 'AE', 'OE', 'UE', '-'],
            $appTitle,
        ));

        // Fixed values
        $appIcon = 'media::icons.app';
        $appRoute = 'media.dashboard';

        $this->line("<comment>App key:</comment> {$appKey}");
        $this->line("<comment>App icon:</comment> {$appIcon}");
        $this->line("<comment>Main route:</comment> {$appRoute}");

        // Check if app already exists
        $existingApp = TenantApp::where('name', $appKey)->first();
        if ($existingApp) {
            $this->warn("App '{$appKey}' already exists in the database.");
            if (! $this->confirm('Do you want to continue anyway?', false)) {
                return;
            }
        } else {
            // Create TenantApp entry
            TenantApp::create([
                'title' => $appTitle,
                'name' => $appKey,
                'icon' => $appIcon,
                'route' => $appRoute,
                'is_active' => true,
            ]);
            $this->line("<info>✓ TenantApp '{$appKey}' created in database</info>");
            $this->installedAppKey = $appKey;
        }
    }

    private function copyDirectoryContents(string $sourceDir, string $targetDir): void
    {
        if (! is_dir($targetDir)) {
            if (! mkdir($targetDir, 0755, true)) {
                throw new Exception("Failed to create directory: {$targetDir}");
            }
            $relativePath = str_replace(base_path('content') . DIRECTORY_SEPARATOR, '', $targetDir);
            $this->line("<info>Created directory:</info> {$relativePath}");
            $this->results['created_dirs']++;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relativePath = mb_substr($sourcePath, mb_strlen($sourceDir) + 1);
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (! is_dir($targetPath)) {
                    if (! mkdir($targetPath, 0755, true)) {
                        throw new Exception("Failed to create directory: {$targetPath}");
                    }
                    $displayPath = str_replace(base_path('content') . DIRECTORY_SEPARATOR, '', $targetPath);
                    $this->line("<info>Created directory:</info> {$displayPath}");
                    $this->results['created_dirs']++;
                }
            } else {
                $displayPath = str_replace(base_path('content') . DIRECTORY_SEPARATOR, '', $targetPath);

                if (file_exists($targetPath)) {
                    if (! $this->option('force')) {
                        $choice = $this->choice(
                            "File already exists: {$displayPath}. What do you want to do?",
                            ['skip', 'overwrite', 'overwrite-all'],
                            'skip',
                        );

                        if ($choice === 'skip') {
                            $this->line("<comment>Skipped:</comment> {$displayPath}");
                            $this->results['skipped_files']++;

                            continue;
                        }
                        if ($choice === 'overwrite-all') {
                            $this->input->setOption('force', true);
                        }
                    }

                    $this->line("<comment>Overwriting:</comment> {$displayPath}");
                    $this->results['overwritten_files']++;
                } else {
                    $this->line("<info>Copying:</info> {$displayPath}");
                    $this->results['copied_files']++;
                }

                if (! copy($sourcePath, $targetPath)) {
                    throw new Exception("Failed to copy file: {$sourcePath} to {$targetPath}");
                }
            }
        }
    }

    private function displaySummary(): void
    {
        $this->line('');
        $this->info('Installation Summary:');
        $this->table(
            ['Operation', 'Count'],
            [
                ['Directories created', $this->results['created_dirs']],
                ['Files copied', $this->results['copied_files']],
                ['Files overwritten', $this->results['overwritten_files']],
                ['Files skipped', $this->results['skipped_files']],
            ],
        );
    }
}
