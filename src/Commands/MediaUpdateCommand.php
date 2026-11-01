<?php

declare(strict_types=1);

namespace Noerd\Media\Commands;

class MediaUpdateCommand extends MediaInstallCommand
{
    protected $signature = 'noerd:update-media';

    protected $description = 'Update Media YML configuration files';
}
