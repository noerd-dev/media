<?php

namespace Noerd\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MediaLabel extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $table = 'media_labels';

    public function medias(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_label_media');
    }
}
