<?php

return [
    'default_provider' => env('AI_DEFAULT_PROVIDER', 'gemini'),

    'max_document_upload_kb' => (int) env('MAX_DOCUMENT_UPLOAD_KB', 10240),

    'allowed_document_extensions' => ['pdf', 'docx', 'txt', 'csv'],

    'allowed_document_mimes' => [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'text/csv',
        'application/csv',
    ],

    'default_daily_token_limit' => (int) env('DEFAULT_DAILY_TOKEN_LIMIT', 250000),

    'chat_rate_limit_per_minute' => (int) env('CHAT_RATE_LIMIT_PER_MINUTE', 30),

    'context' => [
        'max_recent_messages' => 14,
        'summarize_threshold_messages' => 16,
        'max_memories_injected' => 12,
        'max_document_chunks_injected' => 6,
        'chunk_size_chars' => 1200,
        'chunk_overlap_chars' => 200,
    ],

    'providers' => [
        'openai' => [
            'display_name' => 'OpenAI',
            'driver' => 'openai_compatible',
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            'default_model' => env('OPENAI_DEFAULT_MODEL', 'gpt-4o-mini'),
            'models' => [
                ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini', 'context_window' => 128000, 'supports_streaming' => true],
                ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 Mini', 'context_window' => 1000000, 'supports_streaming' => true],
                ['id' => 'gpt-5.4-mini', 'label' => 'GPT-5.4 Mini', 'context_window' => 400000, 'supports_streaming' => true],
                ['id' => 'gpt-5.5', 'label' => 'GPT-5.5', 'context_window' => 400000, 'supports_streaming' => true],
            ],
        ],
        'anthropic' => [
            'display_name' => 'Anthropic Claude',
            'driver' => 'anthropic',
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'default_model' => env('ANTHROPIC_DEFAULT_MODEL', 'claude-sonnet-5-5'),
            'models' => [
                ['id' => 'claude-sonnet-5-5', 'label' => 'Claude Sonnet 5.5', 'context_window' => 1000000, 'supports_streaming' => true],
                ['id' => 'claude-haiku-5-5', 'label' => 'Claude Haiku 5.5', 'context_window' => 1000000, 'supports_streaming' => true],
                ['id' => 'claude-opus-5-5', 'label' => 'Claude Opus 5.5', 'context_window' => 1000000, 'supports_streaming' => true],
                ['id' => 'claude-sonnet-4-6', 'label' => 'Claude Sonnet 4.6', 'context_window' => 1000000, 'supports_streaming' => true],
            ],
        ],
        'gemini' => [
            'display_name' => 'Google Gemini',
            'driver' => 'openai_compatible',
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
            'api_key' => env('GEMINI_API_KEY'),
            'default_model' => env('GEMINI_DEFAULT_MODEL', 'gemini-3.5-flash'),
            'models' => [
                ['id' => 'gemini-3.5-flash', 'label' => 'Gemini 3.5 Flash', 'context_window' => 1048576, 'supports_streaming' => true],
                ['id' => 'gemini-3.6-flash', 'label' => 'Gemini 3.6 Flash', 'context_window' => 1048576, 'supports_streaming' => true],
                ['id' => 'gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash Lite', 'context_window' => 1048576, 'supports_streaming' => true],
                ['id' => 'gemini-3.8-flash', 'label' => 'Gemini 3.8 Flash', 'context_window' => 1048576, 'supports_streaming' => true],
            ],
        ],
        'openrouter' => [
            'display_name' => 'OpenRouter (Multi-Model Gateway)',
            'driver' => 'openai_compatible',
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'api_key' => env('OPENROUTER_API_KEY'),
            'default_model' => env('OPENROUTER_DEFAULT_MODEL', 'openai/gpt-4o-mini'),
            'models' => [
                ['id' => 'openai/gpt-4o-mini', 'label' => 'OpenAI GPT-4o Mini (via OpenRouter)', 'context_window' => 128000, 'supports_streaming' => true],
                ['id' => 'anthropic/claude-3.5-sonnet', 'label' => 'Claude 3.5 Sonnet (via OpenRouter)', 'context_window' => 200000, 'supports_streaming' => true],
                ['id' => 'deepseek/deepseek-chat', 'label' => 'DeepSeek Chat (via OpenRouter)', 'context_window' => 64000, 'supports_streaming' => true],
            ],
        ],
    ],
];

