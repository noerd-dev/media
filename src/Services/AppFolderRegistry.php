<?php

declare(strict_types=1);

namespace Noerd\Media\Services;

/**
 * Folders an app owns in every tenant's media library — a receipt inbox the
 * accounting agent reads, say. A module registers them from its service
 * provider's boot(); AppFolderService creates the rows per tenant, the
 * folders cannot be renamed or deleted by users and are only shown to users
 * who may use the owning app.
 *
 * The media library must not know which modules exist (ModuleBoundaryTest
 * enforces that), so the folders are announced inward rather than listed here.
 * A registration ceases to exist with its module — same reasoning as
 * MediaUsageRegistry.
 */
class AppFolderRegistry
{
    /** @var array<string, array{key: string, app: string, label: string}> */
    private array $folders = [];

    /**
     * @param  string  $key  stable identifier, e.g. `accounting.receipt_import`
     * @param  string  $appName  the tenant app (tenant_apps.name) that owns the folder
     * @param  string  $label  the folder name, an English translation key of the registering module
     */
    public function register(string $key, string $appName, string $label): void
    {
        $this->folders[$key] = [
            'key' => $key,
            'app' => mb_strtoupper($appName),
            'label' => $label,
        ];
    }

    /**
     * @return array<string, array{key: string, app: string, label: string}>
     */
    public function all(): array
    {
        return $this->folders;
    }

    /**
     * @return array<string, array{key: string, app: string, label: string}>
     */
    public function forApp(string $appName): array
    {
        $appName = mb_strtoupper($appName);

        return array_filter($this->folders, fn(array $folder): bool => $folder['app'] === $appName);
    }

    /**
     * @return array{key: string, app: string, label: string}|null
     */
    public function get(string $key): ?array
    {
        return $this->folders[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->folders[$key]);
    }
}
