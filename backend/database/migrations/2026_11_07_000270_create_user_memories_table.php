<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('source_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->string('category', 40)->default('preference')->index();
            $table->string('memory_key', 120);
            $table->text('memory_value');
            $table->unsignedTinyInteger('importance')->default(3);
            $table->boolean('is_confirmed')->default(true)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active', 'is_confirmed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_memories');
    }
};

