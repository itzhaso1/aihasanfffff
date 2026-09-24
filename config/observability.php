<?php

return [

    'correlation_header' => env('OPS_CORRELATION_HEADER', 'X-Correlation-Id'),

    'queues' => [
        'inbound' => env('INBOX_QUEUE_INBOUND', 'inbound'),
        'realtime' => env('INBOX_QUEUE_REALTIME', 'realtime'),
        'ai' => env('INBOX_QUEUE_AI', 'ai'),
        'outbound' => env('INBOX_QUEUE_OUTBOUND', 'outbound'),
        'media' => env('INBOX_QUEUE_MEDIA', 'media'),
        'default' => env('REDIS_QUEUE', 'default'),
    ],

    'alerts' => [
        'queue_depth' => [
            'inbound' => (int) env('OPS_ALERT_INBOUND_DEPTH', 500),
            'realtime' => (int) env('OPS_ALERT_REALTIME_DEPTH', 200),
            'ai' => (int) env('OPS_ALERT_AI_DEPTH', 300),
            'outbound' => (int) env('OPS_ALERT_OUTBOUND_DEPTH', 500),
            'media' => (int) env('OPS_ALERT_MEDIA_DEPTH', 200),
        ],
        'failed_jobs_recent' => (int) env('OPS_ALERT_FAILED_JOBS_1H', 25),
        'webhook_5xx_rate_percent' => (float) env('OPS_ALERT_WEBHOOK_5XX_RATE', 5.0),
        'disk_free_percent_min' => (float) env('OPS_ALERT_DISK_FREE_PERCENT', 10.0),
    ],

    'retention' => [
        'webhook_events_processed_days' => (int) env('OPS_RETAIN_WEBHOOK_EVENTS_DAYS', 30),
        'ai_logs_days' => env('OPS_RETAIN_AI_LOGS_DAYS') !== null && env('OPS_RETAIN_AI_LOGS_DAYS') !== ''
            ? (int) env('OPS_RETAIN_AI_LOGS_DAYS')
            : null,
    ],

    'load_test' => [
        'max_messages_per_run' => (int) env('OPS_LOAD_TEST_MAX_MESSAGES', 500),
    ],

];
