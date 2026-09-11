<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Noerd\Media\Services\MediaPathService;

return new class extends Migration {
    /**
     * The disk mirrors the folder tree, so every folder needs a stable,
     * filesystem-safe directory name. `name` cannot serve: it is user-typed
     * (and for app folders a translation key), unsanitized, and not unique
     * among siblings.
     */
    public function up(): void
    {
        if (! Schema::hasTable('media_folders')) {
            return;
        }

        if (! Schema::hasColumn('media_folders', 'path_segment')) {
            Schema::table('media_folders', function (Blueprint $table): void {
                $table->string('path_segment')->nullable()->after('name');
            });
        }

        $this->backfillSegments();

        if (! $this->uniqueIndexExists()) {
            Schema::table('media_folders', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'parent_id', 'path_segment'], 'media_folders_segment_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('media_folders') || ! Schema::hasColumn('media_folders', 'path_segment')) {
            return;
        }

        Schema::table('media_folders', function (Blueprint $table): void {
            if ($this->uniqueIndexExists()) {
                $table->dropUnique('media_folders_segment_unique');
            }

            $table->dropColumn('path_segment');
        });
    }

    /**
     * Give every existing folder a segment, disambiguating same-named siblings
     * the same way the service does at runtime.
     */
    private function backfillSegments(): void
    {
        $paths = new MediaPathService();
        $taken = [];

        DB::table('media_folders')
            ->select('id', 'tenant_id', 'parent_id', 'name', 'path_segment')
            ->orderBy('id')
            ->each(function (object $folder) use ($paths, &$taken): void {
                if (filled($folder->path_segment)) {
                    $taken[$this->siblingKey($folder) . '|' . $folder->path_segment] = true;

                    return;
                }

                $base = $paths->segmentFor((string) $folder->name);
                $candidate = $base;
                $suffix = 1;

                while (isset($taken[$this->siblingKey($folder) . '|' . $candidate])) {
                    $suffix++;
                    $candidate = $base . '-' . $suffix;
                }

                $taken[$this->siblingKey($folder) . '|' . $candidate] = true;

                DB::table('media_folders')->where('id', $folder->id)->update(['path_segment' => $candidate]);
            });
    }

    private function siblingKey(object $folder): string
    {
        return $folder->tenant_id . ':' . ($folder->parent_id ?? 'root');
    }

    private function uniqueIndexExists(): bool
    {
        foreach (Schema::getIndexes('media_folders') as $index) {
            if (($index['name'] ?? null) === 'media_folders_segment_unique') {
                return true;
            }
        }

        return false;
    }
};
