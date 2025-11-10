<?php

return [
    'providers' => [
        'default' => 'nominatim',
        'nominatim' => [
            'base_url' => env('GEOCODING_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
            'headers' => [
                'User-Agent' => env('GEOCODING_USER_AGENT', sprintf('KidsApp/%s', config('app.version', '1.0'))),
                'From' => env('GEOCODING_CONTACT_EMAIL', 'support@example.com'),
            ],
        ],
    ],

    'http' => [
        'connect_timeout' => (float) env('GEOCODING_CONNECT_TIMEOUT', 2.0),
        'read_timeout' => (float) env('GEOCODING_READ_TIMEOUT', 4.0),
        'retry' => [
            'max_attempts' => (int) env('GEOCODING_RETRY_ATTEMPTS', 3),
            'initial_delay_ms' => (int) env('GEOCODING_RETRY_INITIAL_DELAY', 200),
            'max_delay_ms' => (int) env('GEOCODING_RETRY_MAX_DELAY', 1200),
        ],
    ],

    'throttle' => [
        'rps' => (int) env('GEOCODING_THROTTLE_RPS', 2),
        'window_seconds' => (int) env('GEOCODING_THROTTLE_WINDOW', 1),
    ],

    'cache' => [
        'forward_ttl' => (int) env('GEOCODING_FORWARD_TTL', 60 * 60 * 24 * 14),
        'reverse_ttl' => (int) env('GEOCODING_REVERSE_TTL', 60 * 60 * 24 * 14),
        'search_ttl' => (int) env('GEOCODING_SEARCH_TTL', 60 * 60 * 12),
    ],

    'rounding' => [
        'reverse' => [
            'default' => (int) env('GEOCODING_REVERSE_ROUND_DEFAULT', 4),
            'GEOMETRIC_CENTER' => (int) env('GEOCODING_REVERSE_ROUND_GEOMETRIC', 3),
            'RANGE_INTERPOLATED' => (int) env('GEOCODING_REVERSE_ROUND_RANGE', 4),
            'ROOFTOP' => (int) env('GEOCODING_REVERSE_ROUND_ROOFTOP', 5),
        ],
    ],

    'breaker' => [
        'failure_threshold' => (int) env('GEOCODING_BREAKER_THRESHOLD', 5),
        'interval_seconds' => (int) env('GEOCODING_BREAKER_INTERVAL', 60),
        'open_seconds' => (int) env('GEOCODING_BREAKER_OPEN_FOR', 60),
    ],

    'log_channel' => env('GEOCODING_LOG_CHANNEL', 'performance'),
];
