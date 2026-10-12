<?php

return [

    'queues' => [
        'inbound' => env('INBOX_QUEUE_INBOUND', 'inbound'),
        'realtime' => env('INBOX_QUEUE_REALTIME', 'realtime'),
        'ai' => env('INBOX_QUEUE_AI', 'ai'),
        'outbound' => env('INBOX_QUEUE_OUTBOUND', 'outbound'),
        'media' => env('INBOX_QUEUE_MEDIA', 'media'),
    ],

    'realtime' => [
        'broadcast_connection' => env('INBOX_BROADCAST_CONNECTION', env('BROADCAST_CONNECTION', 'null')),
    ],

];
