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

    // H5 web API is a separate transport: it uses plain browser-like headers
    // (no JWT) and cannot use the signed mobile endpoints above.
    'h5_host' => rtrim((string) env('MOVIEBOX_H5_HOST', 'https://h5-api.aoneroom.com'), '/'),

    // Seconds to cache the bootstrapped bearer token.
    'token_ttl' => (int) env('MOVIEBOX_TOKEN_TTL', 1800),

    // Seconds to cache catalog responses (home/search/detail).
    'cache_ttl' => (int) env('MOVIEBOX_CACHE_TTL', 300),

    // Seconds a persisted MySQL snapshot is served as fresh before re-fetching
    // (it is still used as a fallback beyond this when the API fails).
    'snapshot_ttl' => (int) env('MOVIEBOX_SNAPSHOT_TTL', 900),

    // Persist every individual movie/series received into the catalog_items
    // table (a growing local library, queryable and API-independent).
    'persist_items' => filter_var(env('MOVIEBOX_PERSIST_ITEMS', true), FILTER_VALIDATE_BOOL),

    // On the first web request after the app boots, import the whole catalog
    // (films / séries / animation) and dump it to storage/app/catalog/*.json.
    'import_on_boot' => filter_var(env('MOVIEBOX_IMPORT_ON_BOOT', true), FILTER_VALIDATE_BOOL),

    // Minimum seconds between two automatic boot imports (avoids re-importing
    // on every request; the import runs after the response is sent).
    'import_interval' => (int) env('MOVIEBOX_IMPORT_INTERVAL', 21600),

    // Outbound request timeout, seconds.
    'timeout' => (int) env('MOVIEBOX_TIMEOUT', 30),

    // Host suffixes the DASH/HLS stream proxy (/api/mv/...) is allowed to fetch
    // from. Prevents the proxy being used as an open relay.
    'cdn_proxy_allow' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MOVIEBOX_CDN_PROXY_ALLOW',
        'hakunaymatata.com'
    ))))),

    // Block direct browser navigation to /api/* (someone pasting the URL). The
    // SPA (fetch/XHR) and the native app are unaffected. Disabled by default so
    // /api/... URLs (incl. /api/diagnostics) can be opened directly in a
    // browser for debugging. Set MOVIEBOX_PROTECT_API=true to re-enable.
    'protect_api' => filter_var(env('MOVIEBOX_PROTECT_API', false), FILTER_VALIDATE_BOOL),

    // Content filtering: titles whose genre/title/description match any of these
    // keywords are hidden from DISCOVERY surfaces (home, trending, categories,
    // recommendations, local library). They remain reachable via SEARCH so the
    // filter is a browsing preference, not a hard block. Comma-separated,
    // configurable via env; set MOVIEBOX_BLOCKED_KEYWORDS="" to disable.
    'content_filter_enabled' => filter_var(env('MOVIEBOX_CONTENT_FILTER', true), FILTER_VALIDATE_BOOL),
    'blocked_keywords' => array_values(array_filter(array_map(
        fn ($w) => mb_strtolower(trim($w)),
        explode(',', (string) env(
            'MOVIEBOX_BLOCKED_KEYWORDS',
            'hentai,ecchi,yaoi,yuri,hardcore,porn,porno,pornographic,xxx,x-rated,erotic,erotique,erotica,nsfw,adult,18+,'
            .'sex,sexe,sexuel,sexuelle,sexual,gay,lgbt,lgbtq,lesbian,lesbienne,homosexual,homosexuel,homosexuelle,queer,bara,'
            .'animation,anime,animated,cartoon,dessin animé,dessin anime'
        ))
    ))),

    // Optional outbound proxy.
    'proxy' => env('MOVIEBOX_PROXY') ?: null,

    // Device identity used to sign in to the API. The hardcoded default in
    // client_info below is shared/flagged and gets "find no content" (406) on
    // the streaming endpoints. When these are empty, the client generates a
    // random per-install identity and persists it to storage — behaving like a
    // unique, legitimate app install. Override here only to pin a known-good one.
    'device_id' => env('MOVIEBOX_DEVICE_ID') ?: null,
    'gaid' => env('MOVIEBOX_GAID') ?: null,

    // iOS HEVC fix. MovieBox HEVC files are tagged `hev1`, which Safari/AVPlayer
    // plays as audio-only (no picture). The /api/mv-hevc endpoint proxies the
    // file and rewrites the sample-entry fourcc `hev1` -> `hvc1` on the fly in
    // pure PHP (no ffmpeg — works on shared/cPanel hosting). Range requests are
    // honoured so seeking still works. Set false to disable.
    'hevc_fix' => filter_var(env('MOVIEBOX_HEVC_FIX', true), FILTER_VALIDATE_BOOL),

    // Prefer a fully-converted Streamtape copy as the first `/api/play` source
    // when one exists for the requested title (see StreamController::play).
    'streamtape_fallback' => filter_var(env('MOVIEBOX_STREAMTAPE_FALLBACK', true), FILTER_VALIDATE_BOOL),

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
