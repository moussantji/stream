<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| The frontend is a lightweight single-page app. Every non-API GET request
| returns the same shell view; client-side JavaScript handles routing.
*/

Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|up|storage).*$');
