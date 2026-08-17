<?php

namespace App\Providers;

use App\Services\MovieBox\MovieBoxClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
        $this->scheduleCatalogImportOnBoot();
    }

    /**
     * On startup, dump the full catalog (films/séries/animation) to JSON.
     *
     * The import is run once per interval, after the HTTP response is flushed
     * (via a terminating callback), so it never blocks page loads. A cache flag
     * guarded by an atomic add() ensures a single request triggers it.
     */
    protected function scheduleCatalogImportOnBoot(): void
    {
        // Only trigger from real web requests — not from the CLI (including the
        // import command itself) or the test suite.
        if ($this->app->runningInConsole() || ! config('moviebox.import_on_boot', true)) {
            return;
        }

        try {
            $interval = max(60, (int) config('moviebox.import_interval', 21600));
            $shouldRun = Cache::add('catalog:import:boot-lock', now()->timestamp, $interval);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if (! $shouldRun) {
            return;
        }

        $this->app->terminating(function () {
            // The built-in `artisan serve` worker is single-threaded: running
            // the import inline would freeze the whole site for minutes. Launch
            // it in a detached background process instead.
            $run = fn (string $cmd) => @shell_exec(sprintf(
                'nohup %s %s > %s 2>&1 &',
                escapeshellarg(PHP_BINARY),
                $cmd,
                escapeshellarg(base_path('storage/logs/background.log'))
            ));

            try {
                // Keep startup light; the admin "deep import" fetches more pages.
                $run('artisan catalog:import --pages=3');
            } catch (Throwable $e) {
                report($e);
            }
            // Pre-resolve play payloads for the freshly imported titles so the
            // first click on "Lecture" is a cache hit instead of an 11s cold
            // upstream fan-out.
            try {
                $run('artisan stream:warm --limit=12');
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
