<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title')->default('Percakapan Baru');
            $table->string('assistant_mode', 32)->default('general')->index();
            $table->string('provider_key', 50)->nullable();
            $table->string('model_name', 120)->nullable();
            $table->text('context_summary')->nullable();
            $table->unsignedBigInteger('summarized_until_message_id')->nullable();
            $table->json('attached_document_ids')->nullable();
            $table->boolean('is_pinned')->default(false)->index();
            $table->boolean('is_archived')->default(false)->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_archived', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};

