<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Whether folders may be created inside this one. A tenant admin turns it
     * off for a folder that has to stay flat — an app's import inbox, say,
     * whose pipeline only ever reads the top level.
     */
    public function up(): void
    {
        if (! Schema::hasTable('media_folders') || Schema::hasColumn('media_folders', 'allows_subfolders')) {
            return;
        }

        // An installation that never ran the app-column migration has no
        // system_key to place the column after.
        $after = Schema::hasColumn('media_folders', 'system_key') ? 'system_key' : 'name';

        Schema::table('media_folders', function (Blueprint $table) use ($after): void {
            $table->boolean('allows_subfolders')->default(true)->after($after);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('media_folders') || ! Schema::hasColumn('media_folders', 'allows_subfolders')) {
            return;
        }

        Schema::table('media_folders', function (Blueprint $table): void {
            $table->dropColumn('allows_subfolders');
        });
    }
};
