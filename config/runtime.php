<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Production runtime targets
    |--------------------------------------------------------------------------
    |
    | Local/testing keep database/array/sync via .env / phpunit.xml.
    | Production hosts must set these env values. runtime:check --production-strict
    | fails if they are not Redis (no silent database fallback).
    |
    */

    'production' => [
        'queue_connection' => 'redis',
        'cache_store' => 'redis',
        'session_driver' => 'redis',
        'failed_driver' => 'database-uuids',
    ],

    /*
    | Derived from current jobs: SyncEmailInboxJob::$timeout = 180 is the longest.
    | Worker timeout must cover that job and stay below Redis retry_after so a
    | still-running worker is not assumed dead and the job is not released early.
    */
    'queue' => [
        'name' => env('REDIS_QUEUE', 'default'),
        'redis_connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
        'max_job_timeout' => 180,
        'worker_timeout' => (int) env('QUEUE_WORKER_TIMEOUT', 200),
        'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 270),
        'tries' => (int) env('QUEUE_WORKER_TRIES', 3),
        'backoff' => (int) env('QUEUE_WORKER_BACKOFF', 5),
    ],

    'redis' => [
        'test_database' => (int) env('REDIS_TEST_DB', 14),
        'test_prefix' => env('REDIS_TEST_PREFIX', 'hasem-p04-'),
    ],

];
