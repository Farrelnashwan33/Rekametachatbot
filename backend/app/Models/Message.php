<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Message extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'conversation_id',
        'user_id',
        'parent_message_id',
        'role',
        'content',
        'provider_key',
        'model_name',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'latency_ms',
        'is_edited',
        'is_stopped',
        'is_superseded',
        'feedback',
        'is_bookmarked',
        'citations',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
            'is_edited' => 'boolean',
            'is_stopped' => 'boolean',
            'is_superseded' => 'boolean',
            'is_bookmarked' => 'boolean',
            'citations' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            if (empty($message->uuid)) {
                $message->uuid = (string) Str::uuid();
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_message_id');
    }
}

