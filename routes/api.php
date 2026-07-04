<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\StreamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
| All routes are prefixed with /api. Catalog + streaming endpoints are public;
| account and personal-library endpoints are protected by Sanctum tokens.
*/

// ---- Authentication (Sanctum tokens) --------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// ---- Catalog / discovery (public) -----------------------------------------
Route::middleware('throttle:120,1')->group(function () {
    Route::get('home', [CatalogController::class, 'home']);
    Route::get('trending', [CatalogController::class, 'trending']);
    Route::get('search', [CatalogController::class, 'search']);
    Route::get('suggest', [CatalogController::class, 'suggest'])->middleware('throttle:60,1');
    Route::get('discover', [CatalogController::class, 'discover']);
    Route::get('category', [CatalogController::class, 'category']);
    Route::get('channels', [CatalogController::class, 'channels']);
    Route::get('local', [CatalogController::class, 'local']);
    Route::get('detail', [CatalogController::class, 'detail']);
    Route::get('diagnostics', [CatalogController::class, 'diagnostics']);

    // ---- Streaming (public) -----------------------------------------------
    Route::get('play', [StreamController::class, 'play']);
    Route::get('downloads', [StreamController::class, 'download']);
    Route::get('subtitle', [StreamController::class, 'subtitle']);
});

// Signing proxy for CloudFront-protected DASH/HLS manifests + segments.
// Higher throttle: a manifest pulls many segment requests during playback.
Route::get('mv/{token}/{path}', [StreamController::class, 'proxy'])
    ->where('path', '.*')
    ->middleware('throttle:1000,1');

// ---- Personal library (Sanctum-protected) ---------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::get('favorites', [LibraryController::class, 'favorites']);
    Route::post('favorites', [LibraryController::class, 'storeFavorite']);
    Route::delete('favorites/{subjectId}', [LibraryController::class, 'destroyFavorite']);

    Route::get('history', [LibraryController::class, 'history']);
    Route::post('history', [LibraryController::class, 'storeHistory']);
    Route::delete('history/{subjectId}', [LibraryController::class, 'destroyHistory']);
});

// ---- Admin (Sanctum + is_admin, enforced in the controller) ---------------
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('stats', [AdminController::class, 'stats']);
    Route::get('export-links', [AdminController::class, 'exportLinks']);
    Route::post('import', [AdminController::class, 'import']);
});
