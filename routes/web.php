<?php

use App\Http\Controllers\WebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| The frontend is a lightweight single-page app. The main listing pages
| (home, films, series, detail) are server-rendered from the same cached
| payloads the JSON API serves — first paint without JavaScript — then the
| SPA hydrates from the embedded page-data. Everything else falls back to
| the plain shell; client-side JavaScript handles routing.
*/

Route::get('/', [WebController::class, 'home']);
Route::get('/films', [WebController::class, 'category'])->defaults('tab', 'films');
Route::get('/series', [WebController::class, 'category'])->defaults('tab', 'series');
Route::get('/title/{slug?}', [WebController::class, 'title'])
    ->where('slug', '[a-zA-Z0-9\-]+');
Route::get('/t/{code}', [WebController::class, 'titleByCode'])
    ->where('code', '[0-9a-zA-Z]+');

Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api|up|storage).*$');