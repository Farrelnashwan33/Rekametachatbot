<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'title',
        'original_filename',
        'mime_type',
        'extension',
        'disk',
        'storage_path',
        'file_size',
        'file_hash',
        'status',
        'extracted_text',
        'structured_summary',
        'page_count',
        'word_count',
        'chunk_count',
        'error_message',
        'metadata',
    ];

    protected $hidden = [
        'storage_path',
        'extracted_text',
    ];

    protected function casts(): array
    {
        return [
            'structured_summary' => 'array',
            'metadata' => 'array',
            'file_size' => 'integer',
            'page_count' => 'integer',
            'word_count' => 'integer',
            'chunk_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $doc): void {
            if (empty($doc->uuid)) {
                $doc->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class)->orderBy('chunk_index');
    }
}

