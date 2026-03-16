<?php

namespace Noerd\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Noerd\Media\Database\Factories\MediaFactory;
use Noerd\Traits\BelongsToTenant;
use Nywerk\Uki\Models\TextDocument;

class Media extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $guarded = [];

    protected $table = 'medias';

    public function aiDocument()
    {
        return $this->hasOne(TextDocument::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MediaTag::class, 'media_tag_media');
    }

    protected static function newFactory(): MediaFactory
    {
        return MediaFactory::new();
    }
}
