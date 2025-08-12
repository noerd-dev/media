<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('medias')) {
            Schema::create('medias', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('type')->default('image');
                $table->string('name');
                $table->string('extension')->nullable();
                $table->string('path');
                $table->string('thumbnail')->nullable();
                $table->string('disk')->default(config('media.disk', 'media'));
                $table->unsignedBigInteger('size')->default(0);
                $table->timestamps();

                $table->index(['tenant_id', 'type']);
                $table->index('disk');
            });
        } else {
            // Ensure missing columns exist (idempotent adjustments)
            Schema::table('medias', function (Blueprint $table): void {
                if (!Schema::hasColumn('medias', 'ocr_text')) {
                    $table->longText('ocr_text')->nullable();
                }
                if (!Schema::hasColumn('medias', 'thumbnail')) {
                    $table->string('thumbnail')->nullable();
                }
                if (!Schema::hasColumn('medias', 'disk')) {
                    $table->string('disk')->default(config('media.disk', 'media'));
                }
                if (!Schema::hasColumn('medias', 'size')) {
                    $table->unsignedBigInteger('size')->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        // No destructive down to avoid dropping existing media data
    }
};


