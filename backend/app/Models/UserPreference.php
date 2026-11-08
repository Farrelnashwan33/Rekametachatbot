<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'theme',
        'language',
        'communication_style',
        'memory_enabled',
        'memory_auto_save',
        'default_provider',
        'default_model',
        'voice_enabled',
    ];

    protected function casts(): array
    {
        return [
            'memory_enabled' => 'boolean',
            'memory_auto_save' => 'boolean',
            'voice_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

