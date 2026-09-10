<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * App-owned folders: `app_name` is the tenant app that registered the
     * folder (AppFolderRegistry), `system_key` its stable key — one folder per
     * tenant and key.
     */
    public function up(): void
    {
        if (! Schema::hasTable('media_folders')) {
            return;
        }

        Schema::table('media_folders', function (Blueprint $table): void {
            if (! Schema::hasColumn('media_folders', 'app_name')) {
                $table->string('app_name')->nullable()->after('name');
            }

            if (! Schema::hasColumn('media_folders', 'system_key')) {
                $table->string('system_key')->nullable()->after('app_name');
                $table->unique(['tenant_id', 'system_key']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('media_folders') || ! Schema::hasColumn('media_folders', 'system_key')) {
            return;
        }

        Schema::table('media_folders', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'system_key']);
            $table->dropColumn(['system_key', 'app_name']);
        });
    }
};
