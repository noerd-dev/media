<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * `path_segment` is the folder's directory name on the media disk (the
     * disk mirrors the folder tree). `app_name` + `system_key` mark a folder
     * an app registered through the AppFolderRegistry; `allows_subfolders`
     * is the admin switch that keeps a folder flat.
     */
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('path_segment')->nullable();
            $table->string('app_name')->nullable();
            $table->string('system_key')->nullable();
            $table->boolean('allows_subfolders')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'system_key']);
            $table->unique(['tenant_id', 'parent_id', 'path_segment'], 'media_folders_segment_unique');
            $table->index(['tenant_id', 'parent_id']);
            $table->foreign('parent_id')
                ->references('id')
                ->on('media_folders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_folders');
    }
};
