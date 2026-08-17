<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the whole catalog's detail snapshots warm (newest first) so any
// title serves its full page (seasons/cast/dubs) instantly — no first-visit
// penalty. Kept gentle (2 workers) so the upstream MovieBox hosts never
// see a request flood.
Schedule::command('catalog:warm-details --limit=150 --workers=2 --sleep=800000')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Pre-resolve play payloads for recent items so playback starts instantly.
Schedule::command('stream:warm --limit=12')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();