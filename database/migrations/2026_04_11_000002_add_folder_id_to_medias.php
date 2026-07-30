<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('medias') && ! Schema::hasColumn('medias', 'folder_id')) {
            Schema::table('medias', function (Blueprint $table): void {
                $table->unsignedBigInteger('folder_id')->nullable()->after('tenant_id');
                $table->index(['tenant_id', 'folder_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('medias') && Schema::hasColumn('medias', 'folder_id')) {
            Schema::table('medias', function (Blueprint $table): void {
                $table->dropIndex(['tenant_id', 'folder_id']);
                $table->dropColumn('folder_id');
            });
        }
    }
};
