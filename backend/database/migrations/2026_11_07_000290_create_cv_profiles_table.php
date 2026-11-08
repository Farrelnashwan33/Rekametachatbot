<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cv_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('title')->default('CV Profesional');
            $table->string('template', 32)->default('executive');
            $table->string('full_name');
            $table->string('headline')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('location')->nullable();
            $table->string('website')->nullable();
            $table->string('linkedin')->nullable();
            $table->text('summary')->nullable();
            $table->json('experiences')->nullable();
            $table->json('educations')->nullable();
            $table->json('skills')->nullable();
            $table->json('certifications')->nullable();
            $table->json('projects')->nullable();
            $table->json('languages')->nullable();
            $table->unsignedTinyInteger('ats_score')->nullable();
            $table->json('ats_evaluation')->nullable();
            $table->text('target_job_description')->nullable();
            $table->longText('cover_letter')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cv_profiles');
    }
};

