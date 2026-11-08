<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->string('heading')->nullable();
            $table->text('content');
            $table->unsignedInteger('token_estimate')->default(0);
            $table->json('keywords')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
            $table->index(['user_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};

