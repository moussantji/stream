<?php

namespace App\Jobs;

use App\Services\Catalog\SnapshotRebuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Rebuild one expired catalog snapshot in the background so visitors never
 * wait for the (slow) upstream API. Dispatched by CatalogRepository::remember
 * when a stale snapshot is served; runs via the queue worker (cron on LWS).
 */
class RebuildSnapshot implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(public string $key, public int $ttl) {}

    public function handle(SnapshotRebuilder $rebuilder): void
    {
        try {
            $rebuilder->rebuild($this->key, $this->ttl);
        } catch (Throwable $e) {
            report($e);
        } finally {
            Cache::forget('snapshot:queued:'.$this->key);
        }
    }
}