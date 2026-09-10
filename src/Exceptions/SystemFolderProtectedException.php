<?php

declare(strict_types=1);

namespace Noerd\Media\Exceptions;

use Noerd\Media\Models\MediaFolder;
use RuntimeException;

/**
 * An app owns this folder (AppFolderRegistry): a module reads or files
 * documents there, so a user must neither delete, rename nor move it.
 */
class SystemFolderProtectedException extends RuntimeException
{
    public function __construct(public readonly MediaFolder $folder)
    {
        parent::__construct(__('The folder ":name" belongs to an app and cannot be changed or deleted.', ['name' => $folder->label()]));
    }
}
