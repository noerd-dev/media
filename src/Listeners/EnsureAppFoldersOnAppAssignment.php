<?php

declare(strict_types=1);

namespace Noerd\Media\Listeners;

use Illuminate\Support\Facades\Schema;
use Noerd\Events\TenantAppAssigned;
use Noerd\Media\Services\AppFolderService;

/**
 * The moment a tenant is given an app, the folders that app registered exist
 * in the tenant's media library — before any user opens it and before the
 * app's agent looks for them.
 *
 * An install command assigns the app BEFORE it offers the migrations, so on a
 * fresh database the folder table may not exist yet. The listener then does
 * nothing: the install command's own setup step (`ensureForAllTenants()`), the
 * library's mount and `resolve()` create the folders once it does.
 */
class EnsureAppFoldersOnAppAssignment
{
    public function __construct(private readonly AppFolderService $folders) {}

    public function handle(TenantAppAssigned $event): void
    {
        if (! Schema::hasTable('media_folders') || ! Schema::hasColumn('media_folders', 'system_key')) {
            return;
        }

        $this->folders->ensureForApp($event->tenantId, $event->appName);
    }
}
