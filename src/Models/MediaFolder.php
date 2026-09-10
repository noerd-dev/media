<?php

namespace Noerd\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Noerd\Media\Database\Factories\MediaFolderFactory;
use Noerd\Media\Exceptions\SystemFolderProtectedException;
use Noerd\Media\Scopes\AppFolderVisibilityScope;
use Noerd\Traits\BelongsToTenant;

class MediaFolder extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function medias(): HasMany
    {
        return $this->hasMany(Media::class, 'folder_id');
    }

    /**
     * Whether an app owns this folder (registered through AppFolderRegistry).
     * Such a folder is created per tenant by the module, cannot be renamed,
     * moved or deleted by users and is hidden from users who may not use the
     * app.
     */
    public function isSystem(): bool
    {
        return $this->system_key !== null;
    }

    /**
     * The name to show: an app folder's name is an English translation key of
     * the registering module, a user's folder is shown as typed.
     */
    public function label(): string
    {
        return $this->isSystem() ? __($this->name) : (string) $this->name;
    }

    /**
     * Build a breadcrumb walking up the parent chain.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function breadcrumb(): array
    {
        $crumbs = [];
        $node = $this;
        while ($node) {
            array_unshift($crumbs, ['id' => $node->id, 'name' => $node->label()]);
            $node = $node->parent;
        }

        return $crumbs;
    }

    /**
     * The protection lives on the model rather than in the library screen, so
     * every path is covered — bulk actions, a future rename, tinker.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new AppFolderVisibilityScope());

        static::deleting(function (self $folder): void {
            if ($folder->isSystem()) {
                throw new SystemFolderProtectedException($folder);
            }
        });

        static::updating(function (self $folder): void {
            if ($folder->getOriginal('system_key') === null) {
                return;
            }

            if ($folder->isDirty(['name', 'parent_id', 'app_name', 'system_key'])) {
                throw new SystemFolderProtectedException($folder);
            }
        });
    }

    protected static function newFactory(): MediaFolderFactory
    {
        return MediaFolderFactory::new();
    }
}
