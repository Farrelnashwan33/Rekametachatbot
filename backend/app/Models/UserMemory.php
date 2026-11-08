<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserMemory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'source_conversation_id',
        'category',
        'memory_key',
        'memory_value',
        'importance',
        'is_confirmed',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'importance' => 'integer',
            'is_confirmed' => 'boolean',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'source_conversation_id');
    }
}

