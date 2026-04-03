<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('medias') && ! Schema::hasColumn('medias', 'custom_attributes')) {
            Schema::table('medias', function (Blueprint $table): void {
                $table->json('custom_attributes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('medias') && Schema::hasColumn('medias', 'custom_attributes')) {
            Schema::table('medias', function (Blueprint $table): void {
                $table->dropColumn('custom_attributes');
            });
        }
    }
};
