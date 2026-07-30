<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('media_label_media') && !Schema::hasTable('media_labels')) {
            return;
        }

        // Step 1: Drop foreign key from pivot table before renaming
        if (Schema::hasTable('media_label_media')) {
            Schema::table('media_label_media', function (Blueprint $table): void {
                $table->dropForeign(['media_label_id']);
            });
        }

        // Step 2: Rename main table first
        if (Schema::hasTable('media_labels')) {
            Schema::rename('media_labels', 'media_tags');
        }

        // Step 3: Rename pivot table
        if (Schema::hasTable('media_label_media')) {
            Schema::rename('media_label_media', 'media_tag_media');
        }

        // Step 4: Rename column in pivot table
        if (Schema::hasTable('media_tag_media')) {
            Schema::table('media_tag_media', function (Blueprint $table): void {
                $table->renameColumn('media_label_id', 'media_tag_id');
            });
        }

        // Step 5: Recreate foreign key with correct reference
        if (Schema::hasTable('media_tag_media')) {
            Schema::table('media_tag_media', function (Blueprint $table): void {
                $table->foreign('media_tag_id')->references('id')->on('media_tags')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('media_tag_media') && !Schema::hasTable('media_tags')) {
            return;
        }

        // Step 1: Drop foreign key from pivot table
        if (Schema::hasTable('media_tag_media')) {
            Schema::table('media_tag_media', function (Blueprint $table): void {
                $table->dropForeign(['media_tag_id']);
            });
        }

        // Step 2: Rename column back in pivot table
        if (Schema::hasTable('media_tag_media')) {
            Schema::table('media_tag_media', function (Blueprint $table): void {
                $table->renameColumn('media_tag_id', 'media_label_id');
            });
        }

        // Step 3: Rename pivot table back
        if (Schema::hasTable('media_tag_media')) {
            Schema::rename('media_tag_media', 'media_label_media');
        }

        // Step 4: Rename main table back
        if (Schema::hasTable('media_tags')) {
            Schema::rename('media_tags', 'media_labels');
        }

        // Step 5: Recreate foreign key
        if (Schema::hasTable('media_label_media')) {
            Schema::table('media_label_media', function (Blueprint $table): void {
                $table->foreign('media_label_id')->references('id')->on('media_labels')->onDelete('cascade');
            });
        }
    }
};
