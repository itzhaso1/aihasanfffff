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
    'max_output_characters' => (int) env('AI_MAX_OUTPUT_CHARS', 4000),
    'max_tokens_cap' => (int) env('AI_MAX_TOKENS_CAP', 1024),
    'reply' => [
        'failure_markers' => [
            'تعذر إنشاء الرد الآن',
            'تعذر إنشاء رد مناسب',
            'provider failed',
            'No allowed AI provider',
        ],
    ],
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
    'inbox_agent' => [
        'enabled' => (bool) env('AI_INBOX_AGENT_ENABLED', true),
        'fallback_enabled' => (bool) env('AI_INBOX_AGENT_FALLBACK_ENABLED', true),
        'max_iterations' => (int) env('AI_INBOX_AGENT_MAX_ITERATIONS', 3),
        'max_tool_rounds' => (int) env('AI_INBOX_AGENT_MAX_TOOL_ROUNDS', 5),
        'tools_enabled' => (bool) env('AI_INBOX_AGENT_TOOLS_ENABLED', true),
        'timeout_seconds' => (int) env('AI_INBOX_AGENT_TIMEOUT_SECONDS', 60),
        'security' => [
            'max_tool_argument_chars' => (int) env('AI_INBOX_AGENT_SECURITY_MAX_TOOL_ARGUMENT_CHARS', 2000),
            'max_tool_result_chars' => (int) env('AI_INBOX_AGENT_SECURITY_MAX_TOOL_RESULT_CHARS', 8000),
            'max_duplicate_tool_calls' => (int) env('AI_INBOX_AGENT_SECURITY_MAX_DUPLICATE_TOOL_CALLS', 2),
            'default_max_calls_per_tool' => (int) env('AI_INBOX_AGENT_SECURITY_DEFAULT_MAX_CALLS_PER_TOOL', 3),
        ],
        'context' => [
            'max_messages' => (int) env('AI_INBOX_AGENT_CONTEXT_MAX_MESSAGES', 8),
            'max_message_chars' => (int) env('AI_INBOX_AGENT_CONTEXT_MAX_MESSAGE_CHARS', 400),
            'max_history_chars' => (int) env('AI_INBOX_AGENT_CONTEXT_MAX_TOTAL_CHARS', 4000),
            'max_business_chars' => (int) env('AI_INBOX_AGENT_CONTEXT_MAX_BUSINESS_CHARS', 3500),
            'max_system_chars' => (int) env('AI_INBOX_AGENT_CONTEXT_MAX_SYSTEM_CHARS', 12000),
        ],
    ],
    'inbox_memory' => [
        'queue' => env('AI_INBOX_MEMORY_QUEUE', 'default'),
        'enabled' => (bool) env('AI_INBOX_MEMORY_ENABLED', true),
        'min_confidence' => (float) env('AI_INBOX_MEMORY_MIN_CONFIDENCE', 0.85),
        'max_candidates' => (int) env('AI_INBOX_MEMORY_MAX_CANDIDATES', 5),
        'max_retrieved' => (int) env('AI_INBOX_MEMORY_MAX_RETRIEVED', 8),
        'max_value_chars' => (int) env('AI_INBOX_MEMORY_MAX_VALUE_CHARS', 500),
        'conversation_ttl_days' => (int) env('AI_INBOX_MEMORY_CONVERSATION_TTL_DAYS', 30),
        'allow_inferred' => (bool) env('AI_INBOX_MEMORY_ALLOW_INFERRED', false),
    ],
];
