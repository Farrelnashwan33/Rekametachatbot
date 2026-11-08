<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    protected $fillable = [
        'document_id',
        'user_id',
        'chunk_index',
        'heading',
        'content',
        'token_estimate',
        'keywords',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'metadata' => 'array',
            'chunk_index' => 'integer',
            'token_estimate' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

