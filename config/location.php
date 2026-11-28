<?php

return [
    'capture_token_ttl_hours' => (int) env('LOCATION_CAPTURE_TTL_HOURS', 24),

    'max_updates_per_conversation_hour' => (int) env('LOCATION_MAX_UPDATES_PER_HOUR', 10),

    'weak_accuracy_threshold_meters' => (int) env('LOCATION_WEAK_ACCURACY_METERS', 150),

    'max_accuracy_meters' => (int) env('LOCATION_MAX_ACCURACY_METERS', 50000),

    'geocoding' => [
        'enabled' => (bool) env('LOCATION_REVERSE_GEOCODING_ENABLED', true),
        'driver' => env('LOCATION_GEOCODING_DRIVER', 'nominatim'),
        'timeout_seconds' => (int) env('LOCATION_GEOCODING_TIMEOUT', 6),
        'accept_language' => env('LOCATION_GEOCODING_LANGUAGE', 'ar,en'),
        'nominatim_base_url' => env('LOCATION_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'nominatim_user_agent' => env('LOCATION_NOMINATIM_USER_AGENT', env('APP_NAME', 'HASEM').'/1.0'),
        'nominatim_email' => env('LOCATION_NOMINATIM_EMAIL'),
        'google_maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'map' => [
        'default_lat' => (float) env('LOCATION_DEFAULT_CENTER_LAT', 24.7136),
        'default_lng' => (float) env('LOCATION_DEFAULT_CENTER_LNG', 46.6753),
        'tile_url' => env('LOCATION_MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    ],

    'rate_limits' => [
        'view_per_minute' => (int) env('LOCATION_VIEW_RATE_LIMIT', 30),
        'submit_per_minute' => (int) env('LOCATION_SUBMIT_RATE_LIMIT', 10),
        'search_per_minute' => (int) env('LOCATION_SEARCH_RATE_LIMIT', 20),
    ],
];
