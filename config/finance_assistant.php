<?php

return [
    'max_tools_per_request' => (int) env('FINANCE_ASSISTANT_MAX_TOOLS', 5),
    'planner' => env('FINANCE_ASSISTANT_PLANNER', 'llm'),
    'heuristic_planner_in_testing' => (bool) env('FINANCE_ASSISTANT_HEURISTIC_IN_TESTING', true),
    'min_period_year' => (int) env('FINANCE_ASSISTANT_MIN_PERIOD_YEAR', 2000),
    // Canonical path is the Gemini agent. Planner remains a compatibility fallback only.
    'agent_enabled' => filter_var(env('FINANCE_ASSISTANT_AGENT', true), FILTER_VALIDATE_BOOLEAN),
    'planner_fallback' => filter_var(env('FINANCE_ASSISTANT_PLANNER_FALLBACK', true), FILTER_VALIDATE_BOOLEAN),
    'security' => [
        'max_argument_depth' => (int) env('FINANCE_ASSISTANT_MAX_ARGUMENT_DEPTH', 4),
        'max_argument_keys' => (int) env('FINANCE_ASSISTANT_MAX_ARGUMENT_KEYS', 20),
        'max_argument_string' => (int) env('FINANCE_ASSISTANT_MAX_ARGUMENT_STRING', 200),
        'max_calls_per_tool' => (int) env('FINANCE_ASSISTANT_MAX_CALLS_PER_TOOL', 4),
        'max_duplicate_calls' => (int) env('FINANCE_ASSISTANT_MAX_DUPLICATE_CALLS', 2),
        'max_request_seconds' => (int) env('FINANCE_ASSISTANT_MAX_REQUEST_SECONDS', 45),
    ],
    'max_tool_rounds' => (int) env('FINANCE_ASSISTANT_MAX_TOOL_ROUNDS', 5),
    'max_tool_calls_per_request' => (int) env('FINANCE_ASSISTANT_MAX_TOOL_CALLS', 10),
    'conversation_max_messages' => (int) env('FINANCE_ASSISTANT_CONVERSATION_MESSAGES', 8),
    'conversation_message_chars' => (int) env('FINANCE_ASSISTANT_CONVERSATION_CHARS', 500),
    'conversation_processing_timeout' => (int) env('FINANCE_ASSISTANT_CONVERSATION_LOCK_SECONDS', 90),
];
