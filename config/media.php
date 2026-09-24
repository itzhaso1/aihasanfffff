<?php

return [

    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),

    'queue' => env('INBOX_QUEUE_MEDIA', 'media'),

    'signed_url_ttl_minutes' => (int) env('MEDIA_SIGNED_URL_TTL', 30),

    'max_bytes' => [
        'image' => (int) env('MEDIA_MAX_BYTES_IMAGE', 16 * 1024 * 1024),
        'audio' => (int) env('MEDIA_MAX_BYTES_AUDIO', 16 * 1024 * 1024),
        'video' => (int) env('MEDIA_MAX_BYTES_VIDEO', 64 * 1024 * 1024),
        'file' => (int) env('MEDIA_MAX_BYTES_FILE', 25 * 1024 * 1024),
        'default' => (int) env('MEDIA_MAX_BYTES_DEFAULT', 25 * 1024 * 1024),
    ],

    'outbound' => [
        'whatsapp' => ['image', 'audio', 'video', 'file'],
        'instagram' => ['image'],
        'facebook_messenger' => ['image', 'file'],
    ],

    'retention' => [
        'failed_download_days' => (int) env('MEDIA_FAILED_RETENTION_DAYS', 30),
    ],

];
