<?php

/*
|--------------------------------------------------------------------------
| LegiScan Driver Configuration
|--------------------------------------------------------------------------
|
| LegiScan is a comprehensive legislative data platform providing access to
| legislative information from all 50 states and the federal government. This
| driver integrates with the LegiScan API and registers itself with the
| Lobbyist manager under the "legiscan" name (the default driver).
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | API Endpoint
    |--------------------------------------------------------------------------
    |
    | Your LegiScan API key (register at https://legiscan.com/user/register)
    | and the base URI of the API.
    |
    */
    'endpoint' => [
        'api_key' => env('LEGISCAN_API_KEY'),
        'base_uri' => env('LEGISCAN_BASE_URI', 'https://api.legiscan.com/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Settings
    |--------------------------------------------------------------------------
    */
    'request' => [
        'timeout' => (int) env('LEGISCAN_TIMEOUT', 30),
        'retry_times' => (int) env('LEGISCAN_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('LEGISCAN_RETRY_SLEEP_MS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Caching
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => env('LEGISCAN_CACHE_ENABLED', true),
        'store' => env('LEGISCAN_CACHE_STORE', env('CACHE_STORE')),
        'ttl' => (int) env('LEGISCAN_CACHE_TTL', 3600),
    ],
];
