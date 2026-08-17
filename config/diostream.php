<?php

/*
|--------------------------------------------------------------------------
| DioStream configuration
|--------------------------------------------------------------------------
|
| DioStream (diostream.cc) is a Netflix-style streaming backend. Catalog
| endpoints (search / metadata / latest) return their JSON encrypted with
| AES-256-GCM using a shared key; stream endpoints return plaintext JSON.
|
| Integration map (reverse-engineered from the SPA bundle, verified live):
|
|   Catalog (GCM-encrypted):  GET /metadata/search?q=..&type=all&sort=popular
|                             GET /metadata/movie/{tmdb}
|                             GET /metadata/tv/{tmdb}?episodes=true
|                             GET /library/latest/episodes?page=1
|   Streams (plaintext):      GET /{sourcePath}/movie/{tmdb}?verify=false&hevc=0
|                             GET /{sourcePath}/tv/{tmdb}/{season}/{episode}?verify=false&hevc=0
|   Source health:            GET /library/source-order, /library/src-health
|
| Every stream/movie/tv payload is a base64 blob of [12-byte IV || AES-256-GCM
| ciphertext || 16-byte tag] under the key below.
|
*/

return [

    // API base URL.
    'base' => rtrim((string) env('DIOSTREAM_BASE', 'https://diostream.cc'), '/'),

    // AES-256-GCM decryption key (hex). Extracted from the SPA bundle; override
    // via env if the upstream ever rotates it.
    'aes_key' => env(
        'DIOSTREAM_AES_KEY',
        'a888f761dbb626bb00bf47cedb3c34c3f60742dda425117db3fe8e65fda2c1a8'
    ),

    // Seconds to cache catalog responses.
    'cache_ttl' => (int) env('DIOSTREAM_CACHE_TTL', 300),

    // Outbound request timeout, seconds.
    'timeout' => (int) env('DIOSTREAM_TIMEOUT', 10),

    // Minimum milliseconds between two outbound requests. The upstream rate
    // limits burst traffic (HTTP 429), so consecutive stream lookups are paced.
    'pace_ms' => (int) env('DIOSTREAM_PACE_MS', 500),

    // Retry attempts on HTTP 429 (after pacing).
    'rate_retries' => (int) env('DIOSTREAM_RATE_RETRIES', 2),

    // Browser-like identity used in request headers.
    'user_agent' => env(
        'DIOSTREAM_USER_AGENT',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
    ),

    // Referer / Origin sent with every request.
    'referer' => env('DIOSTREAM_REFERER', 'https://diostream.cc/'),

    // Default stream source (see sources() for the full table). Keys map to
    // one of the 43 bundled backends; 'helios' = Atlas (EN, fast, MP4),
    // 'hephaestus' = Hephaestus (FR, HLS).
    'default_source' => env('DIOSTREAM_DEFAULT_SOURCE', 'helios'),

    // Alternative stream source used when the default returned no providers.
    'fallback_source' => env('DIOSTREAM_FALLBACK_SOURCE', 'hephaestus'),

];
