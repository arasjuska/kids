<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // OpenStreetMap Nominatim service configuration
    'nominatim' => [
        // Base URL for the API
        'url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),

        // Default ISO country code for searches (lowercase)
        'default_country' => env('NOMINATIM_DEFAULT_COUNTRY', 'lt'),

        // Cache duration in minutes
        'cache_duration' => (int) env('NOMINATIM_CACHE_MINUTES', 1440),

        // Identify your application per Nominatim usage policy.
        // Include a contact email in the UA or separate header.
        'user_agent' => env('NOMINATIM_USER_AGENT', env('APP_NAME', 'Laravel App')),
        'email' => env('NOMINATIM_EMAIL', null),

        // Dev helper: when true and app.debug, return a small fake dataset on failures
        'fake_on_fail' => (bool) env('NOMINATIM_FAKE_ON_FAIL', false),
    ],

];
