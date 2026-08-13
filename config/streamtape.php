<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Streamtape API
    |--------------------------------------------------------------------------
    | Credentials come from your Streamtape dashboard (API section). The
    | `headers` sent when Streamtape fetches the remote URL are built in
    | App\Services\Streamtape\StreamtapeService.
    */

    // API-Login.
    'login' => (string) env('STREAMTAPE_LOGIN', ''),

    // API-Key / API-Password.
    'key' => (string) env('STREAMTAPE_KEY', ''),

    // Folder-ID to upload into (optional; leave empty for the root folder).
    'folder' => (string) env('STREAMTAPE_FOLDER', ''),

    // API base URL (free accounts use api.streamtape.com).
    'api_host' => (string) env('STREAMTAPE_API_HOST', 'https://api.streamtape.com'),

    // Request timeout in seconds.
    'timeout' => (int) env('STREAMTAPE_TIMEOUT', 30),

    // How long (in minutes) after the remote transfer is seen as "finished"
    // do we keep polling before declaring the conversion a failure. Streamtape
    // converts downloaded files *after* marking the transfer finished, and
    // file/info only flips to converted+thumb at the very end — so a "done"
    // link with converted=false is usually just mid-conversion, not broken.
    'convert_grace_min' => (int) env('STREAMTAPE_CONVERT_GRACE_MIN', 60),

    // Maximum automatic resolution-fallback attempts when a file genuinely
    // fails to convert on Streamtape (next-lower resolution each time).
    'convert_max_fallbacks' => (int) env('STREAMTAPE_CONVERT_MAX_FALLBACKS', 3),
];
