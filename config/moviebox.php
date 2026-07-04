<?php

/*
|--------------------------------------------------------------------------
| MovieBox (aoneroom) mobile API configuration
|--------------------------------------------------------------------------
|
| This app talks to the signed "wefeed-mobile-bff" API used by the MovieBox
| Android app (the same one the upstream `Simatwa/moviebox-api` v3 client
| targets). Requests are HMAC-signed and load-balanced across a pool of API
| hosts with automatic failover.
|
*/

return [

    // API host pool (tried in order, with failover on 4xx/5xx retry codes).
    'host_pool' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MOVIEBOX_HOST_POOL',
        'https://api6.aoneroom.com,https://api5.aoneroom.com,https://api4.aoneroom.com,https://api4sg.aoneroom.com,https://api3.aoneroom.com,https://api6sg.aoneroom.com,https://api.inmoviebox.com'
    ))))),

    // Seconds to cache the bootstrapped bearer token.
    'token_ttl' => (int) env('MOVIEBOX_TOKEN_TTL', 1800),

    // Seconds to cache catalog responses (home/search/detail).
    'cache_ttl' => (int) env('MOVIEBOX_CACHE_TTL', 300),

    // Seconds a persisted MySQL snapshot is served as fresh before re-fetching
    // (it is still used as a fallback beyond this when the API fails).
    'snapshot_ttl' => (int) env('MOVIEBOX_SNAPSHOT_TTL', 900),

    // Outbound request timeout, seconds.
    'timeout' => (int) env('MOVIEBOX_TIMEOUT', 30),

    // Optional outbound proxy.
    'proxy' => env('MOVIEBOX_PROXY') ?: null,

    // HMAC signing secret (base64). Override only if the upstream rotates it.
    'secret_key' => env('MOVIEBOX_SECRET_KEY', '76iRl07s0xSN9jqmEWAt79EBJZulIQIsV64FZr2O'),

    /*
    | The MovieBox mobile API has NO language/region parameter — its default
    | landing page is Bollywood-heavy regardless of settings. To get a French
    | browsing experience we instead build the home page from a set of search
    | queries (the one lever that reliably surfaces French / French-dubbed
    | titles). Each entry is "Row label|search query" (or just "query").
    | Set MOVIEBOX_HOME_QUERIES="" to fall back to the provider's landing page.
    */
    'home_queries' => (function () {
        $raw = env('MOVIEBOX_HOME_QUERIES');
        $entries = $raw !== null
            ? ($raw === '' ? [] : explode(',', $raw))
            : [
                'Films en français|film français',
                'Version française|version française',
                'Comédie|comédie française',
                'Action|action française',
                'Séries en français|série française',
                'Animation|animation française',
            ];

        return array_values(array_filter(array_map(function ($entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                return null;
            }
            [$label, $query] = array_pad(explode('|', $entry, 2), 2, null);
            $label = trim((string) $label);
            $query = trim((string) ($query ?? $label));

            return $query === '' ? null : ['label' => $label ?: $query, 'query' => $query];
        }, $entries)));
    })(),

    // Query used for the "Trending" row / page (no real trending-by-language exists).
    'trending_query' => env('MOVIEBOX_TRENDING_QUERY', 'français'),

    // Region/language identity (informational only; the API ignores it for content).
    'region' => strtoupper((string) env('MOVIEBOX_REGION', 'FR')),
    'language' => strtolower((string) env('MOVIEBOX_LANGUAGE', 'fr')),
    'timezone' => env('MOVIEBOX_TIMEZONE', 'Europe/Paris'),

    // Android app identity used in request headers.
    'user_agent' => env(
        'MOVIEBOX_USER_AGENT',
        'com.community.oneroom/50020045 (Linux; U; Android 13; fr_FR; 23078RKD5C; Build/TQ2A.230405.003; Cronet/135.0.7012.3)'
    ),

    'client_info' => env('MOVIEBOX_CLIENT_INFO', json_encode([
        'package_name' => 'com.community.oneroom',
        'version_name' => '3.0.03.0529.03',
        'version_code' => 50020045,
        'os' => 'android',
        'os_version' => '13',
        'install_ch' => 'ps',
        'device_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'install_store' => 'ps',
        'gaid' => 'b6f3a2c1-4d5e-6f70-8192-a3b4c5d6e7f8',
        'brand' => 'Redmi',
        'model' => '23078RKD5C',
        'system_language' => strtolower((string) env('MOVIEBOX_LANGUAGE', 'fr')),
        'net' => 'NETWORK_WIFI',
        'region' => strtoupper((string) env('MOVIEBOX_REGION', 'FR')),
        'timezone' => env('MOVIEBOX_TIMEZONE', 'Europe/Paris'),
        'sp_code' => '40401',
        'X-Play-Mode' => '2',
    ])),
];
