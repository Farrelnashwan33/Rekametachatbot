<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider_key', 50)->unique();
            $table->string('display_name', 120);
            $table->string('driver', 40)->default('openai_compatible');
            $table->string('base_url');
            $table->text('api_key_encrypted')->nullable();
            $table->string('default_model', 120);
            $table->json('available_models')->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('supports_streaming')->default(true);
            $table->unsignedInteger('max_output_tokens')->default(4096);
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->unsignedInteger('daily_token_limit_per_user')->default(250000);
            $table->unsignedInteger('rate_limit_per_minute')->default(30);
            $table->json('extra_headers')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_settings');
    }
};

