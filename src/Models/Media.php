<?php

namespace Noerd\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Noerd\Media\Database\Factories\MediaFactory;
use Noerd\Traits\BelongsToTenant;
use Noerd\Uki\Models\TextDocument;

class Media extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /**
     * Extensions a browser can render directly in an <img> tag. Used as the
     * fallback when no generated thumbnail exists.
     */
    private const INLINE_RENDERABLE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'avif', 'gif'];

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

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    /**
     * Resolve the URL of the original file, honoring the private-media toggle.
     * Public mode returns the direct /storage URL; private mode returns the
     * authenticated streaming route.
     */
    public function url(): string
    {
        if (config('media.private')) {
            return route('media.file', $this);
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Resolve the URL of the thumbnail (falling back to the original), honoring
     * the private-media toggle.
     */
    public function thumbnailUrl(): string
    {
        if (config('media.private')) {
            return route('media.thumbnail', $this);
        }

        return Storage::disk($this->disk)->url($this->thumbnail ?? $this->path);
    }

    /**
     * Whether thumbnailUrl() resolves to something an <img> tag can display.
     * False for files without a generated thumbnail whose original is not an
     * image itself (e.g. a PDF on an installation without Ghostscript) — those
     * must be rendered as a file-type tile instead of a broken image.
     */
    public function hasRenderableThumbnail(): bool
    {
        if (filled($this->thumbnail)) {
            return true;
        }

        return in_array($this->normalizedExtension(), self::INLINE_RENDERABLE_EXTENSIONS, true);
    }

    /**
     * Lowercased file extension, falling back to the stored path.
     */
    public function normalizedExtension(): string
    {
        return mb_strtolower((string) ($this->extension ?: pathinfo((string) $this->path, PATHINFO_EXTENSION)));
    }

    protected static function newFactory(): MediaFactory
    {
        return MediaFactory::new();
    }

    protected function casts(): array
    {
        return [
            'custom_attributes' => 'array',
        ];
    }
}
