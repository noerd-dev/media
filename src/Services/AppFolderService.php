<?php

declare(strict_types=1);

namespace Noerd\Media\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Noerd\Media\Models\MediaFolder;
use Noerd\Models\Tenant;

/**
 * Creates the folders registered in AppFolderRegistry per tenant. Every query
 * runs WITHOUT the global scopes and carries the tenant id explicitly: the
 * receipt agent resolves folders from the scheduler, where no user is signed
 * in, and an admin assigning an app acts in a different tenant than the one
 * that receives the folder.
 */
class AppFolderService
{
    /** @var array<int, true> */
    private array $ensured = [];

    public function __construct(private readonly AppFolderRegistry $registry) {}

    /**
     * Create the missing folders of every app the tenant holds. Idempotent and
     * memoized per request — the media library calls it on every mount.
     */
    public function ensureForTenant(int $tenantId): void
    {
        if (isset($this->ensured[$tenantId]) || $this->registry->all() === []) {
            return;
        }

        $assigned = $this->assignedAppNames($tenantId);

        foreach ($this->registry->all() as $definition) {
            if (isset($assigned[$definition['app']])) {
                $this->findOrCreate($tenantId, $definition);
            }
        }

        $this->ensured[$tenantId] = true;
    }

    /**
     * Create the folders of ONE app for a tenant — the moment the app is
     * assigned (TenantAppAssigned), before any user opened the library.
     */
    public function ensureForApp(int $tenantId, string $appName): void
    {
        foreach ($this->registry->forApp($appName) as $definition) {
            $this->findOrCreate($tenantId, $definition);
        }
    }

    /**
     * Catch up every tenant — the install and update commands run this so an
     * existing installation gets the folders of a newly registering module.
     */
    public function ensureForAllTenants(): void
    {
        foreach (Tenant::query()->orderBy('id')->pluck('id') as $tenantId) {
            unset($this->ensured[$tenantId]);
            $this->ensureForTenant((int) $tenantId);
        }
    }

    /**
     * The registered folder of a tenant, created on demand. This is what a
     * module calls when it needs the folder (to file a document into it) — it
     * never depends on an event having fired first.
     */
    public function resolve(int $tenantId, string $key): MediaFolder
    {
        $definition = $this->registry->get($key)
            ?? throw new InvalidArgumentException("No app folder is registered under the key [{$key}].");

        return $this->findOrCreate($tenantId, $definition);
    }

    public function forget(): void
    {
        $this->ensured = [];
    }

    /**
     * @return array<string, int> uppercase app names as keys
     */
    private function assignedAppNames(int $tenantId): array
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant) {
            return [];
        }

        return $tenant->tenantApps()
            ->pluck('name')
            ->map(fn($name): string => mb_strtoupper((string) $name))
            ->flip()
            ->all();
    }

    /**
     * @param  array{key: string, app: string, label: string}  $definition
     */
    private function findOrCreate(int $tenantId, array $definition): MediaFolder
    {
        $existing = $this->find($tenantId, $definition['key']);

        if ($existing) {
            // A module may rename its label between versions; the folder
            // follows quietly — the rename guard is meant for users.
            if ($existing->name !== $definition['label'] || $existing->app_name !== $definition['app']) {
                $existing->forceFill(['name' => $definition['label'], 'app_name' => $definition['app']])->saveQuietly();
            }

            return $existing;
        }

        try {
            return MediaFolder::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'parent_id' => null,
                'name' => $definition['label'],
                'app_name' => $definition['app'],
                'system_key' => $definition['key'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // The scheduler and a user opening the library raced; the row
            // exists now, whoever wrote it.
            return $this->find($tenantId, $definition['key']) ?? throw new InvalidArgumentException(
                "The app folder [{$definition['key']}] could not be created for tenant {$tenantId}.",
            );
        }
    }

    private function find(int $tenantId, string $key): ?MediaFolder
    {
        return MediaFolder::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('system_key', $key)
            ->first();
    }
}
