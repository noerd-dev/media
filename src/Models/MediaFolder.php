<?php

namespace Noerd\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Noerd\Media\Database\Factories\MediaFolderFactory;
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
     * Build a breadcrumb walking up the parent chain.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function breadcrumb(): array
    {
        $crumbs = [];
        $node = $this;
        while ($node) {
            array_unshift($crumbs, ['id' => $node->id, 'name' => $node->name]);
            $node = $node->parent;
        }

        return $crumbs;
    }

    protected static function newFactory(): MediaFolderFactory
    {
        return MediaFolderFactory::new();
    }
}
