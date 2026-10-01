<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration {
    /**
     * The generated directories used to be dot directories (`.thumbnails`,
     * `.variants`). The stock nginx configuration of Forge denies every path
     * with a dot segment, so in public mode every thumbnail of the library
     * answered 403 in production. This moves both trees of every tenant to the
     * reserved `_thumbnails` / `_variants` names and rewrites the stored
     * thumbnail paths. It is idempotent: a second run finds nothing to move.
     */
    private const RENAMES = [
        '.thumbnails' => '_thumbnails',
        '.variants' => '_variants',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('medias')) {
            return;
        }

        foreach ($this->disks() as $disk) {
            $this->moveDirectories($disk);
        }

        $this->rewriteThumbnailPaths();
    }

    /**
     * Moving the files back would only bring the 403 back.
     */
    public function down(): void {}

    /**
     * The configured media disk plus every disk a record names, as long as the
     * disk is still configured.
     *
     * @return array<int, string>
     */
    private function disks(): array
    {
        $disks = DB::table('medias')->distinct()->pluck('disk')->filter()->all();
        $disks[] = (string) config('media.disk', 'media');

        return array_values(array_filter(
            array_unique($disks),
            fn(string $disk): bool => config("filesystems.disks.{$disk}") !== null,
        ));
    }

    private function moveDirectories(string $diskName): void
    {
        $disk = Storage::disk($diskName);

        foreach ($disk->directories() as $tenantDirectory) {
            if (! ctype_digit($tenantDirectory)) {
                continue;
            }

            foreach (self::RENAMES as $legacy => $reserved) {
                $source = $tenantDirectory . '/' . $legacy;
                $target = $tenantDirectory . '/' . $reserved;

                if (! $disk->directoryExists($source)) {
                    continue;
                }

                foreach ($disk->allFiles($source) as $file) {
                    $destination = $target . mb_substr($file, mb_strlen($source));

                    if ($disk->exists($destination)) {
                        $disk->delete($file);

                        continue;
                    }

                    $disk->move($file, $destination);
                }

                $disk->deleteDirectory($source);
            }
        }
    }

    private function rewriteThumbnailPaths(): void
    {
        DB::table('medias')
            ->where('thumbnail', 'like', '%/.thumbnails/%')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('medias')->where('id', $row->id)->update([
                        'thumbnail' => preg_replace('#^(\d+)/\.thumbnails/#', '$1/_thumbnails/', (string) $row->thumbnail),
                    ]);
                }
            });
    }
};
