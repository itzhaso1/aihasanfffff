<?php

return [

    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),

    'webhook_allowed_updates' => [
        'message',
        'business_connection',
        'business_message',
        'edited_business_message',
    ],

];
