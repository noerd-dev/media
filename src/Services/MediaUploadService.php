<?php

namespace Noerd\Media\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Noerd\Media\Models\Media;
use Noerd\Media\Models\MediaFolder;
use Noerd\Support\DropzoneFile;

class MediaUploadService
{
    public function __construct(
        private readonly ImagePreviewService $imagePreviewService,
        private readonly MediaPathService $paths,
    ) {}

    /**
     * Store a file described by a dropzone array into medias and disk, and return the Media model.
     *
     * Only the signed `_original` upload reference is trusted. The plain
     * scalars of that array are client-controlled (it lives in a public
     * Livewire property), so name, extension and size are read back off the
     * resolved upload rather than taken from the payload — a fabricated entry
     * therefore resolves to nothing instead of pointing the writer at an
     * arbitrary file on the server.
     *
     * The target folder is passed in rather than applied afterwards: the disk
     * mirrors the library, so the folder decides where the bytes go.
     *
     * @throws InvalidArgumentException when the entry describes no live upload
     */
    public function storeFromArray(array $file, ?int $folderId = null): Media
    {
        $upload = DropzoneFile::resolve($file);

        if (! $upload instanceof UploadedFile) {
            throw new InvalidArgumentException('The upload could not be resolved.');
        }

        return $this->storeFromUploadedFile($upload, $folderId);
    }

    /**
     * Store a file the CALLER already has on the local file system.
     *
     * This is the trusted counterpart of storeFromArray(): a console import
     * reading a directory the operator named, a job filing a generated
     * document. The path is used as given, so it must never come from a
     * request — anything that reaches this from a browser payload is an
     * arbitrary file read. Web uploads go through storeFromArray() /
     * storeFromUploadedFile(), which only trust the signed upload reference.
     *
     * @throws InvalidArgumentException when the path is not a readable file
     */
    public function storeFromPath(string $path, ?int $folderId = null, ?string $name = null): Media
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The file could not be read: ' . $path);
        }

        return $this->storeFromUploadedFile(
            new UploadedFile($path, $name ?? basename($path), null, null, true),
            $folderId,
        );
    }

    public function storeFromUploadedFile($uploadedFile, ?int $folderId = null): Media
    {
        $tenantId = (int) Auth::user()->selected_tenant_id;
        $folder = $this->resolveFolder($folderId);
        // The row must name the folder the bytes actually went into: a folder
        // id that did not resolve (unknown, or another tenant's) writes to the
        // library root, so the record has to say root as well.
        $folderId = $folder?->id;

        $extension = $uploadedFile->getClientOriginalExtension();
        $size = $uploadedFile->getSize();

        $name = $this->paths->uniqueFilename($tenantId, $folder, $uploadedFile->getClientOriginalName());
        $destinationPath = $this->paths->pathFor($tenantId, $folder, $name);

        $disk = config('media.disk');
        $stream = fopen($uploadedFile->getRealPath(), 'r');
        Storage::disk($disk)->put($destinationPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $fileMeta = [
            'name' => $name,
            'extension' => $extension,
            'size' => $size,
        ];
        $previewPath = $this->imagePreviewService->createPreviewForFile($fileMeta, $destinationPath);

        return Media::create([
            'tenant_id' => $tenantId,
            'folder_id' => $folderId,
            'path' => $destinationPath,
            'type' => 'image',
            'name' => $name,
            'extension' => $extension,
            'size' => $size,
            'disk' => $disk,
            'thumbnail' => $previewPath ?? null,
        ]);
    }

    /**
     * Convenience: return the URL for a stored media file, honoring the
     * private-media toggle (direct /storage URL in public mode, authenticated
     * route in private mode).
     */
    public function publicUrl(Media $media): string
    {
        return $media->url();
    }

    /**
     * The upload target may be an app folder the acting user cannot SEE in the
     * library (AppFolderVisibilityScope hides it), so the visibility scopes are
     * lifted — but the folder must still belong to the acting tenant. The
     * folder id reaches this service from a public Livewire property, and
     * without the tenant condition a rewritten id would write another tenant's
     * directory tree. Unknown or foreign ids fall back to the library root.
     */
    private function resolveFolder(?int $folderId): ?MediaFolder
    {
        if ($folderId === null) {
            return null;
        }

        return MediaFolder::withoutGlobalScopes()
            ->where('tenant_id', (int) Auth::user()->selected_tenant_id)
            ->find($folderId);
    }
}
