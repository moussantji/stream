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
            $repo->storeRaw('catalog:detail:'.$subjectId, $payload, 0);
            Cache::forget('catalog:detail-warm:'.$subjectId);

            $this->releaseSlot($subjectId);

            return 0;
        } catch (Throwable $e) {
            report($e);
            Cache::forget('catalog:detail-warm:'.$subjectId);
            $this->releaseSlot($subjectId);

            $this->error($e->getMessage());

            return 1;
        }
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