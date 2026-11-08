<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Conversation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'title',
        'assistant_mode',
        'provider_key',
        'model_name',
        'context_summary',
        'summarized_until_message_id',
        'attached_document_ids',
        'is_pinned',
        'is_archived',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'attached_document_ids' => 'array',
            'is_pinned' => 'boolean',
            'is_archived' => 'boolean',
            'last_message_at' => 'datetime',
            'summarized_until_message_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $conv): void {
            if (empty($conv->uuid)) {
                $conv->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    public function activeMessages(): HasMany
    {
        return $this->hasMany(Message::class)
            ->where('is_superseded', false)
            ->orderBy('id');
    }
}

