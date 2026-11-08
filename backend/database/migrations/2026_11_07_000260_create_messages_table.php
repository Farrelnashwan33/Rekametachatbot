<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('role', 20)->index();
            $table->longText('content');
            $table->string('provider_key', 50)->nullable();
            $table->string('model_name', 120)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('is_edited')->default(false);
            $table->boolean('is_stopped')->default(false);
            $table->boolean('is_superseded')->default(false)->index();
            $table->string('feedback', 16)->nullable();
            $table->boolean('is_bookmarked')->default(false)->index();
            $table->json('citations')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'is_superseded', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};

