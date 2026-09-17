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
    | Bulk Datasets
    |--------------------------------------------------------------------------
    |
    | A dataset archive holds an entire session — every bill, roll call
    | (including per-member positions) and member — and is fetched in a single
    | request, which is dramatically cheaper against a metered key than pulling
    | records one at a time. Archives run to tens of megabytes, so they are
    | streamed to disk rather than buffered, and are never cached as API
    | responses.
    |
    | Leave the directory null to use the system temporary directory. A download
    | belongs to whoever requested it and should be deleted once imported.
    |
    | Enabling reuse_existing skips the download entirely when a file from a
    | previous run is already on disk for that session and dataset hash, so a
    | database that gets wiped and rebuilt repeatedly during local development
    | does not re-download the same archive from LegiScan every time. A file is
    | only ever reused for the exact hash it was downloaded for; once LegiScan
    | republishes a session under a new hash, that archive downloads fresh.
    |
    */
    'dataset' => [
        'directory' => env('LEGISCAN_DATASET_DIR'),
        'timeout' => (int) env('LEGISCAN_DATASET_TIMEOUT', 600),
        'reuse_existing' => env('LEGISCAN_DATASET_REUSE_EXISTING', false),
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

    /*
    |--------------------------------------------------------------------------
    | Quota Tracking
    |--------------------------------------------------------------------------
    |
    | LegiScan meters API keys by query count per calendar month (30,000 on the
    | standard plan) and does not report usage back in its responses, so this
    | package counts its own requests. The counter resets automatically at
    | midnight UTC on the 1st of each month.
    |
    */
    'quota' => [
        'enabled' => env('LEGISCAN_QUOTA_TRACKING_ENABLED', true),
        'limit' => (int) env('LEGISCAN_QUOTA_LIMIT', 30000),
        'store' => env('LEGISCAN_QUOTA_CACHE_STORE', env('CACHE_STORE')),
        'cache_key' => env('LEGISCAN_QUOTA_CACHE_KEY', 'lobbyist-legiscan:quota'),
    ],
];
