<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DoodStream API
    |--------------------------------------------------------------------------
    | API key from your DoodStream dashboard.
    */

    'api_key' => env('DOODSTREAM_API_KEY', ''),

    // API base URL
    'api_host' => env('DOODSTREAM_API_HOST', 'https://doodapi.com'),

    // Default folder ID (optional)
    'folder' => env('DOODSTREAM_FOLDER', ''),

    // Request timeout in seconds
    'timeout' => (int) env('DOODSTREAM_TIMEOUT', 30),
];
