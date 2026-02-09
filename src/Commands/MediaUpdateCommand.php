<?php

namespace Noerd\Media\Commands;

class MediaUpdateCommand extends NoerdMediaInstallCommand
{
    protected $signature = 'noerd:update-media {--force : Overwrite existing files without asking}';

    protected $description = 'Update Media YML configuration files';

    public function handle(): int
    {
        return $this->runModuleUpdate();
    }
}
