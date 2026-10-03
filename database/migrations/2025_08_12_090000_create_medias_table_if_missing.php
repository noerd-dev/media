<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('medias', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('folder_id')->nullable();
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
            $table->index(['tenant_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medias');
    }
};
