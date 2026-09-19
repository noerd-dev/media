<?php

declare(strict_types=1);

namespace Noerd\Media\Exceptions;

use Noerd\Media\Models\MediaFolder;
use RuntimeException;

/**
 * A tenant admin blocked sub-folders on this folder (`allows_subfolders =
 * false`): an import inbox whose pipeline only reads the top level, a drop
 * folder a consumer watches. Sub-folders that existed when the block was set
 * stay — only new ones are refused. The rule lives on the model, so every path
 * is covered: the library screen, the folder-create modal, a move, tinker.
 */
class SubfoldersNotAllowedException extends RuntimeException
{
    public function __construct(public readonly MediaFolder $folder)
    {
        parent::__construct(__('The folder ":name" does not allow subfolders.', ['name' => $folder->label()]));
    }
}
