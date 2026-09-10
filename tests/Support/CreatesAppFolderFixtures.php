<?php

declare(strict_types=1);

namespace Noerd\Media\Tests\Support;

use Illuminate\Support\Facades\Gate;
use Noerd\Helpers\AccessHelper;
use Noerd\Helpers\TenantHelper;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Services\AppFolderAccess;
use Noerd\Media\Services\AppFolderRegistry;
use Noerd\Models\NoerdUser;
use Noerd\Models\Tenant;
use Noerd\Models\TenantApp;

/**
 * A made-up tenant app that registers a media folder — the media module is
 * tested without any of the modules that actually register one.
 */
trait CreatesAppFolderFixtures
{
    protected string $zzFolderAppName = 'ZZ-FOLDER-APP';

    protected string $zzFolderKey = 'zz.inbox';

    protected string $zzFolderLabel = 'ZZ Inbox';

    protected function zzFolderApp(): TenantApp
    {
        return TenantApp::firstOrCreate(['name' => $this->zzFolderAppName], [
            'title' => 'ZZ Folder App',
            'icon' => 'noerd::icons.app',
            'route' => 'media.dashboard',
            'is_active' => true,
        ]);
    }

    protected function zzRegisterFolder(?string $label = null): void
    {
        app(AppFolderRegistry::class)->register($this->zzFolderKey, $this->zzFolderAppName, $label ?? $this->zzFolderLabel);
    }

    protected function zzAssignFolderApp(int $tenantId): void
    {
        Tenant::query()->findOrFail($tenantId)->tenantApps()->syncWithoutDetaching([$this->zzFolderApp()->id]);
        $this->zzForgetAccess();
    }

    protected function zzUnassignFolderApp(int $tenantId): void
    {
        Tenant::query()->findOrFail($tenantId)->tenantApps()->detach($this->zzFolderApp()->id);
        $this->zzForgetAccess();
    }

    /**
     * The app permission denies the folder app — what a noerd-plus grant
     * decides for a user without access to the app.
     */
    protected function zzDenyFolderApp(): void
    {
        $denied = $this->zzFolderAppName;

        Gate::define(AccessHelper::APP_GATE, fn(?NoerdUser $user, string $appName): bool => mb_strtoupper($appName) !== $denied);
        $this->zzForgetAccess();
    }

    protected function zzSystemFolder(int $tenantId): ?MediaFolder
    {
        return MediaFolder::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('system_key', $this->zzFolderKey)
            ->first();
    }

    /**
     * Both memos are per request; a test that changes the assignment or the
     * permission mid-way has to drop them like a new request would.
     */
    protected function zzForgetAccess(): void
    {
        TenantHelper::clearCache();
        app(AppFolderAccess::class)->forget();
    }
}
