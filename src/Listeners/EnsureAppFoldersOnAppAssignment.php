<?php

declare(strict_types=1);

namespace Noerd\Media\Listeners;

use Noerd\Events\TenantAppAssigned;
use Noerd\Media\Services\AppFolderService;

/**
 * The moment a tenant is given an app, the folders that app registered exist
 * in the tenant's media library — before any user opens it and before the
 * app's agent looks for them.
 */
class EnsureAppFoldersOnAppAssignment
{
    public function __construct(private readonly AppFolderService $folders) {}

    public function handle(TenantAppAssigned $event): void
    {
        $this->folders->ensureForApp($event->tenantId, $event->appName);
    }
}
