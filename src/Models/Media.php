<?php

namespace Noerd\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Nywerk\Uki\Database\Factories\MediaFactory;
use Nywerk\Uki\Models\TextDocument;

class Media extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $table = 'medias';

    public function aiDocument()
    {
        return $this->hasOne(TextDocument::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(MediaLabel::class, 'media_label_media');
    }

    protected static function newFactory(): MediaFactory
    {
        return MediaFactory::new();
    }
}
