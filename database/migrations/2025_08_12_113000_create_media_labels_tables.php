<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
            $table->timestamps();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'name']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        Schema::create('media_tag_media', function (Blueprint $table): void {
            $table->unsignedBigInteger('media_tag_id');
            $table->unsignedBigInteger('media_id');
            $table->timestamps();

            $table->primary(['media_tag_id', 'media_id']);
            $table->foreign('media_tag_id')->references('id')->on('media_tags')->onDelete('cascade');
            $table->foreign('media_id')->references('id')->on('medias')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_tag_media');
        Schema::dropIfExists('media_tags');
    }
};
