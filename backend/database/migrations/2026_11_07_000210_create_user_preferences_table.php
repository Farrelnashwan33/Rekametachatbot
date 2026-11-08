<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('theme', 20)->default('system');
            $table->string('language', 10)->default('id');
            $table->string('communication_style', 30)->default('balanced');
            $table->boolean('memory_enabled')->default(true);
            $table->boolean('memory_auto_save')->default(false);
            $table->string('default_provider', 50)->nullable();
            $table->string('default_model', 100)->nullable();
            $table->boolean('voice_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};

