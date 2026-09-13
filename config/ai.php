<?php

return [
    'default_provider' => env('AI_DEFAULT_PROVIDER', 'google_ai_studio'),
    'allowed_providers' => ['google_ai_studio', 'openai'],
    'allowed_models' => [
        'google_ai_studio' => array_values(array_filter([
            env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'gemini-2.5-flash',
            'gemini-2.0-flash',
        ])),
        'openai' => array_values(array_filter([
            env('OPENAI_MODEL', 'gpt-4o-mini'),
            'gpt-4o-mini',
        ])),
    ],
    'max_input_characters' => (int) env('AI_MAX_INPUT_CHARS', 8000),
    'max_tokens_cap' => (int) env('AI_MAX_TOKENS_CAP', 1024),
    'logging' => [
        'store_content' => (bool) env('AI_LOG_STORE_CONTENT', false),
        // Null = no automated purge and no claimed legal retention period.
        'retention_days' => env('AI_LOG_RETENTION_DAYS') !== null && env('AI_LOG_RETENTION_DAYS') !== ''
            ? (int) env('AI_LOG_RETENTION_DAYS')
            : null,
    ],
    'openai' => [
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('OPENAI_TEMPERATURE', 0.4),
        'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 512),
    ],
    'google_ai_studio' => [
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'temperature' => (float) env('GEMINI_TEMPERATURE', 0.3),
        'max_tokens' => (int) env('GEMINI_MAX_TOKENS', 1024),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],
];
