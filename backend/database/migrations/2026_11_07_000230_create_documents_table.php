<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('original_filename');
            $table->string('mime_type', 120);
            $table->string('extension', 16)->index();
            $table->string('disk', 32)->default('local');
            $table->string('storage_path');
            $table->unsignedBigInteger('file_size');
            $table->string('file_hash', 64)->index();
            $table->string('status', 24)->default('processing')->index();
            $table->longText('extracted_text')->nullable();
            $table->json('structured_summary')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};

