<?php

/*
|--------------------------------------------------------------------------
| MovieBox (aoneroom) backend configuration
|--------------------------------------------------------------------------
|
| This app talks directly to the same public "h5 BFF" endpoints that the
| upstream Python project `Simatwa/moviebox-api` uses. The values below
| control which mirror host is used, request behaviour and caching.
|
*/

return [

    // The h5 web BFF host (home / trending / detail / play / download).
    'host' => env('MOVIEBOX_HOST', 'h5.aoneroom.com'),

    // The API host used for search + search-suggest. This endpoint returns
    // the bearer token (in the `x-user` response header) used to authorise
    // subsequent requests.
    'api_host' => env('MOVIEBOX_API_HOST', 'h5-api.aoneroom.com'),

    'scheme' => env('MOVIEBOX_SCHEME', 'https'),

    // Seconds to cache the bootstrapped bearer token.
    'token_ttl' => (int) env('MOVIEBOX_TOKEN_TTL', 1800),

    // Seconds to cache catalog responses (home/trending/search/detail).
    'cache_ttl' => (int) env('MOVIEBOX_CACHE_TTL', 300),

    // Outbound request timeout, seconds.
    'timeout' => (int) env('MOVIEBOX_TIMEOUT', 30),

    // Optional outbound proxy.
    'proxy' => env('MOVIEBOX_PROXY') ?: null,

    // Referer used specifically for media / subtitle download requests.
    'download_referer' => env('MOVIEBOX_DOWNLOAD_REFERER', 'https://fmoviesunblocked.net/'),

    'user_agent' => env(
        'MOVIEBOX_USER_AGENT',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'
    ),
];
