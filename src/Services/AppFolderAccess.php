<?php

declare(strict_types=1);

namespace Noerd\Media\Services;

use Noerd\Helpers\AccessHelper;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Scopes\AppFolderVisibilityScope;

/**
 * Which folders the current user must not see: every app folder whose app the
 * user may not use (not assigned to the tenant, or denied by the app
 * permission — noerd-plus grants included) plus everything below it.
 * Memoized per request; AppFolderVisibilityScope asks on every query.
 */
class AppFolderAccess
{
    /** @var array<int, array<int, int>> */
    private array $hidden = [];

    private bool $computing = false;

    /**
     * @return array<int, int>
     */
    public function hiddenFolderIds(int $tenantId): array
    {
        if ($this->computing) {
            return [];
        }

        return $this->hidden[$tenantId] ??= $this->compute($tenantId);
    }

    public function forget(): void
    {
        $this->hidden = [];
    }

    /**
     * @return array<int, int>
     */
    private function compute(int $tenantId): array
    {
        $this->computing = true;

        try {
            $appFolders = MediaFolder::withoutGlobalScope(AppFolderVisibilityScope::class)
                ->where('tenant_id', $tenantId)
                ->whereNotNull('app_name')
                ->get(['id', 'app_name']);

            if ($appFolders->isEmpty()) {
                return [];
            }

            $deniedApps = $appFolders->pluck('app_name')
                ->unique()
                ->reject(fn(string $appName): bool => AccessHelper::canUseApp($appName))
                ->flip()
                ->all();

            if ($deniedApps === []) {
                return [];
            }

            $roots = $appFolders
                ->filter(fn(MediaFolder $folder): bool => isset($deniedApps[$folder->app_name]))
                ->pluck('id')
                ->map(fn($id): int => (int) $id)
                ->all();

            return $this->withDescendants($tenantId, $roots);
        } finally {
            $this->computing = false;
        }
    }

    /**
     * @param  array<int, int>  $roots
     * @return array<int, int>
     */
    private function withDescendants(int $tenantId, array $roots): array
    {
        $childrenByParent = MediaFolder::withoutGlobalScope(AppFolderVisibilityScope::class)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id')
            ->map(fn($children) => $children->pluck('id')->map(fn($id): int => (int) $id)->all())
            ->all();

        $hidden = [];
        $queue = $roots;

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($hidden[$id])) {
                continue;
            }

            $hidden[$id] = $id;

            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }

        return array_values($hidden);
    }
}
