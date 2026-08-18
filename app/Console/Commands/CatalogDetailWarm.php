<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\CatalogController;
use App\Services\Catalog\CatalogRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Throwable;

class CatalogDetailWarm extends Command
{
    protected $signature = 'catalog:detail-warm {subjectId} {subjectType=0}';

    protected $description = 'Resolve and cache the full detail payload for a subject in the background (seasons, cast, dubs).';

    public function handle(CatalogController $controller, CatalogRepository $repo): int
    {
        $subjectId = (string) $this->argument('subjectId');
        $subjectType = (int) $this->argument('subjectType');

        try {
            $rc = new ReflectionClass($controller);
            $payload = $rc->getMethod('buildDetail')->invokeArgs($controller, [
                ['subjectId' => $subjectId, 'subjectType' => $subjectType, 'title' => '', 'cover' => ''],
                false,
            ]);

            // A failed build (upstream unavailable, empty detail) must never
            // poison the snapshot: only complete payloads are persisted, and
            // an existing stale one is dropped so the next visit rebuilds.
            if (($payload['detailAvailable'] ?? false) !== true) {
                $repo->deleteKey('catalog:detail:'.$subjectId);

                return $this->retryLater($subjectId);
            }

            $repo->storeRaw('catalog:detail:'.$subjectId, $payload, 0);

            // Pre-resolve the stream payload for the default episode so the
            // first "Lecture" click is a cache hit instead of a cold multi-call
            // resolution (the slow part of starting playback).
            app(\App\Http\Controllers\Api\StreamController::class)->warmPlay($subjectId, $subjectType);

            Cache::forget('catalog:detail-warm:'.$subjectId);

            $this->releaseSlot($subjectId);

            return 0;
        } catch (Throwable $e) {
            report($e);
            $this->retryLater($subjectId);

            $this->error($e->getMessage());

            return 1;
        }
    }

    private function retryLater(string $subjectId): int
    {
        Cache::forget('catalog:detail-warm:'.$subjectId);
        $this->releaseSlot($subjectId);

        return 1;
    }

    private function releaseSlot(string $subjectId): void
    {
        Cache::forget('catalog:warm-slot:'.$subjectId);
        $list = (array) Cache::get('catalog:warm-slotlist', []);
        if (array_key_exists($subjectId, $list)) {
            unset($list[$subjectId]);
            Cache::put('catalog:warm-slotlist', $list, now()->addHour());
        }
    }
}