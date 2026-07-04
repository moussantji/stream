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

    /*
    | Fallback mirrors for the /play and /download endpoints. When the primary
    | host returns no playable resource (hasResource=false), the app tries these
    | in order and caches whichever works. aoneroom is great for browsing but
    | often serves no media in some regions, while other mirrors do.
    |
    | Format (comma separated): "host" or "host|apiHost".
    | e.g. MOVIEBOX_MIRRORS="lok-lok.cc, moviebox.ph|h5-api.aoneroom.com"
    */
    'mirrors' => (function () {
        $raw = env('MOVIEBOX_MIRRORS');
        $entries = ($raw !== null && $raw !== '')
            ? explode(',', $raw)
            : ['lok-lok.cc']; // sensible default: a commonly-working mirror

        return array_values(array_filter(array_map(function ($entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                return null;
            }
            [$host, $apiHost] = array_pad(explode('|', $entry), 2, null);
            $host = trim((string) $host);

            return $host === '' ? null : [
                'host' => $host,
                'api_host' => trim((string) ($apiHost ?: $host)),
            ];
        }, $entries)));
    })(),

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
