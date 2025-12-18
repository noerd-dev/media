<?php

namespace Noerd\Media\Commands;

use Illuminate\Console\Command;
use Noerd\Noerd\Traits\HasModuleInstallation;
use Noerd\Noerd\Traits\RequiresNoerdInstallation;

class NoerdMediaInstallCommand extends Command
{
    use HasModuleInstallation;
    use RequiresNoerdInstallation;

    protected $signature = 'noerd:install-media {--force : Overwrite existing files without asking}';

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
        return dirname(__DIR__, 2) . '/app-contents/media';
    }

    protected function getSnippetTitle(): string
    {
        return 'Media';
    }
}
