<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('media_folders')) {
            Schema::create('media_folders', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('name');
                $table->timestamps();

                $table->index(['tenant_id', 'parent_id']);
                $table->foreign('parent_id')
                    ->references('id')
                    ->on('media_folders')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_folders');
    }
};
