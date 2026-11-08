<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CvProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'source_document_id',
        'title',
        'template',
        'full_name',
        'headline',
        'email',
        'phone',
        'location',
        'website',
        'linkedin',
        'summary',
        'experiences',
        'educations',
        'skills',
        'certifications',
        'projects',
        'languages',
        'ats_score',
        'ats_evaluation',
        'target_job_description',
        'cover_letter',
    ];

    protected function casts(): array
    {
        return [
            'experiences' => 'array',
            'educations' => 'array',
            'skills' => 'array',
            'certifications' => 'array',
            'projects' => 'array',
            'languages' => 'array',
            'ats_evaluation' => 'array',
            'ats_score' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $cv): void {
            if (empty($cv->uuid)) {
                $cv->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }
}

