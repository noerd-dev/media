<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('media_labels')) {
            Schema::create('media_labels', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('name');
                $table->timestamps();

                $table->index('tenant_id');
                $table->unique(['tenant_id', 'name']);
                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('media_label_media')) {
            Schema::create('media_label_media', function (Blueprint $table): void {
                $table->unsignedBigInteger('media_label_id');
                $table->unsignedBigInteger('media_id');
                $table->timestamps();

                $table->primary(['media_label_id', 'media_id']);
                $table->foreign('media_label_id')->references('id')->on('media_labels')->onDelete('cascade');
                $table->foreign('media_id')->references('id')->on('medias')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_label_media');
        Schema::dropIfExists('media_labels');
    }
};


