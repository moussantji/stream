<?php

namespace App\Providers;

use App\Services\MovieBox\MovieBoxClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One client per request lifecycle so the bootstrapped bearer token
        // and cookie jar are shared across the endpoints hit during a request.
        $this->app->scoped(MovieBoxClient::class, fn () => new MovieBoxClient);
    }

    public function boot(): void
    {
        //
    }
}
