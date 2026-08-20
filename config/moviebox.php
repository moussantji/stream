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
    'snapshot_ttl' => (int) env('MOVIEBOX_SNAPSHOT_TTL', 1800),

    // Items per category API page, and the French search queries used to build
    // the /films, /series and /animation pools (the raw upstream tabs are a
    // fixed 5-tile panel that ignores pagination). The pool auto-extends by
    // fetching deeper search pages (category_search_depth) as the user scrolls.
    'category_page_size' => max(10, (int) env('MOVIEBOX_CATEGORY_PAGE_SIZE', 20)),
    // Films & series pages are single-shot: the whole pool is built until it
    // holds this many qualifying (top-rated & recent) titles, then returned
    // in one response.
    'category_pool_cap' => max(50, (int) env('MOVIEBOX_CATEGORY_POOL_CAP', 300)),
    'category_search_depth' => max(1, (int) env('MOVIEBOX_CATEGORY_SEARCH_DEPTH', 8)),
    'category_queries' => [
        'films' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'MOVIEBOX_CATEGORY_FILMS',
            'film d\'action français,film d\'horreur français,film comédie française,film comédie romantique français,film dramatique français,film science-fiction français,film policier français,film aventure français,film thriller français,film fantasy français'
        ))))),
        'series' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'MOVIEBOX_CATEGORY_SERIES',
            'série française,série d\'action française,série dramatique française,série comédie française,série policière française,série fantastique française'
        ))))),
        'animation' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'MOVIEBOX_CATEGORY_ANIMATION',
            'animation française,film d\'animation français,série d\'animation française,dessin animé français,film d\'animation aventure'
        ))))),
    ],

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

    // Shorter timeout for page-data API calls (home / trending / detail …):
    // a slow upstream must fail fast so the stale-if-error snapshots keep the
    // pages fast instead of hanging on a stuck API host.
    'api_timeout' => (int) env('MOVIEBOX_API_TIMEOUT', 10),

    // Max retryable responses (429/5xx) tolerated across the host pool before
    // giving up — each retry costs up to api_timeout seconds.
    'api_retries' => (int) env('MOVIEBOX_API_RETRIES', 1),

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
            'hentai,ecchi,yaoi,yuri,bara,hardcore,porn,porno,pornographique,pornographic,xxx,x-rated,xrated,erotic,erotique,erotica,'
            .'nsfw,adult,adulte,18+,18 ,sex,sexy,sexe,sexuel,sexuelle,sexual,sexuelle,sexual,sex tape,sextape,'
            .'gay,lgbt,lgbtq,lesbian,lesbienne,homosexual,homosexuel,homosexuelle,queer,transgender,transsexuel,transsexuelle,'
            .'shemale,milf,anal,sexe anal,'
            .'nude,nudité,nu,eroticisme,érotisme,'
            .'anime xxx'
        ))
    ))),

    // Systemic junk filter for the softcore / padded-upload family: no per-title
    // blocks needed. A title is filtered when it has a short synopsis AND either
    // (a) contains any soft_keywords phrase, or (b) has a very low rating with
    // no "serious" genre. Movies with a full synopsis are never filtered.
    'junk_rating' => (float) env('MOVIEBOX_JUNK_RATING', 4.8),
    'junk_min_desc' => (int) env('MOVIEBOX_JUNK_MIN_DESC', 300),
    'junk_safe_genres' => array_values(array_filter(array_map(
        fn ($w) => mb_strtolower(trim($w)),
        explode(',', (string) env('MOVIEBOX_JUNK_SAFE_GENRES', 'documentaire,biographie,guerre,histoire,sport,famille,familial,animation'))
    ))),
    'soft_keywords' => array_values(array_filter(array_map(
        fn ($w) => mb_strtolower(trim($w)),
        explode(',', (string) env(
            'MOVIEBOX_SOFT_KEYWORDS',
            'sex tape,sextape,initiation sexuelle,plan à trois,ménage à trois,ébat amoureux,ébat sexuel,'
            .'nymphomane,dévergondé,dévergondée,coquin,coquine,libido,voluptueux,voluptueuse,voyeur,'
            .'jeune étudiante,pension de jeunes,pensionnat,fille de barrio,decouverte de la sexualite,'
            .'découverte de la sexualité,son premier amant,premier amant,première expérience sexuelle,'
            .'hot girl,naughty,nympho,horny,babysitter,porn star,pornstar,sneaky,lesh,lezzie'
        ))
    ))),
    'hard_block_keywords' => array_values(array_filter(array_map(
        fn ($w) => mb_strtolower(trim($w)),
        explode(',', (string) env(
            'MOVIEBOX_HARD_BLOCK_KEYWORDS',
            'porn,hentai,yaoi,yuri,bara,ecchi,x-rated,hardcore,milf,cock,pussy,tits,titties,boobs,boob,'
            .'cumshot,cumshot,bukkake,gangbang,gang bang,blowjob,fellatio,rimjob,creampie,fisting,masturbat,'
            .'dildo,vibrator,shemale,penis,fuck,fucked,fucking,fuckin,'
            .'pornhub,xvideos,redtube,brazzers,youporn,tube8,spankbang,nhentai,caribbeancom,カリビアン,jav,'
            .'moins 20 ans,moins 18 ans,moins de 18 ans,interdit aux moins,18+'
        ))
    ))),

    // Title-only hard filter: any title containing one of these phrases is
    // dropped from every surface (home, categories, search, suggestions,
    // detail) with no metadata check. "The Animation" is the naming pattern
    // of hentai OVAs ("XXX: The Animation") — always blocked.
    'hard_block_titles' => array_values(array_filter(array_map(
        fn ($w) => mb_strtolower(trim($w)),
        explode(',', (string) env('MOVIEBOX_HARD_BLOCK_TITLES', 'the animation'))
    ))),

    // Subject types never displayed anywhere (home, search, categories,
    // suggestions, detail, recommendations): 6 = MUSIC (songs/music videos
    // like Summer Walker – "Come Thru"). Comma-separated ints.
    'blocked_subject_types' => array_values(array_filter(array_map(
        fn ($t) => (int) trim($t),
        explode(',', (string) env('MOVIEBOX_BLOCKED_SUBJECT_TYPES', '6'))
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
    'home_section_size' => (int) env('MOVIEBOX_HOME_SECTION_SIZE', 20),

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

    // Top-rated & recent-only filter, applied on the provider's own fields
    // (imdbRatingValue / releaseDate, no external metadata): an item is kept
    // only when its API rating >= top_rating_min AND its release year
    // >= top_year_min, then rows are sorted by rating then year.
    'top_rating_min' => (float) env('MOVIEBOX_TOP_RATING_MIN', 7.0),
    'top_year_min' => (int) env('MOVIEBOX_TOP_YEAR_MIN', 2024),

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
