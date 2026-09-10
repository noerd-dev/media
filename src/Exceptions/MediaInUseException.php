<?php

declare(strict_types=1);

namespace Noerd\Media\Exceptions;

use RuntimeException;

/**
 * The file is still needed somewhere and must not be deleted. Carries the
 * reason a registered module gave (see MediaUsageRegistry), so the library can
 * tell the user WHY instead of just refusing.
 */
class MediaInUseException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
