<?php

declare(strict_types=1);

namespace Noerd\Media\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Noerd\Helpers\NoerdAuth;
use Noerd\Helpers\TenantHelper;
use Noerd\Media\Models\MediaFolder;
use Noerd\Media\Services\AppFolderAccess;

/**
 * Hides app folders (and the files in them) from users who may not use the
 * owning app. Applied to MediaFolder AND Media, so the folder tiles, the
 * global search, the folder picker, route-model binding of the file routes
 * and every future query are covered in one place.
 *
 * Same contract as TenantScope: only a signed-in user with a selected tenant
 * is filtered. Console commands, queue workers and unauthenticated requests
 * carry no such context and see everything; a service acting for a specific
 * tenant lifts it with withoutGlobalScope(AppFolderVisibilityScope::class).
 */
class AppFolderVisibilityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! NoerdAuth::check()) {
            return;
        }

        $tenantId = TenantHelper::currentTenantId();

        if (! $tenantId) {
            return;
        }

        $hidden = app(AppFolderAccess::class)->hiddenFolderIds($tenantId);

        if ($hidden === []) {
            return;
        }

        $table = $model->getTable();

        if ($model instanceof MediaFolder) {
            $builder->whereNotIn($table . '.id', $hidden);

            return;
        }

        $builder->where(function (Builder $query) use ($table, $hidden): void {
            $query->whereNull($table . '.folder_id')
                ->orWhereNotIn($table . '.folder_id', $hidden);
        });
    }
}
