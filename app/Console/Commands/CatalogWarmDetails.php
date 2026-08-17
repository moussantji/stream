<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Services\Catalog\CatalogRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class CatalogWarmDetails extends Command
{
    protected $signature = 'catalog:warm-details {--limit=500} {--workers=6} {--sleep=300000}';

    protected $description = 'Pre-cache the full detail snapshots (seasons/cast/dubs) for the catalog so any title serves instantly, first and second visit alike.';

    public function handle(CatalogRepository $repo): int
    {
        $limit = max(1, min(3000, (int) $this->option('limit')));
        $workers = max(1, min(12, (int) $this->option('workers')));
        $sleep = max(50000, (int) $this->option('sleep'));

        $ttl = (int) config('moviebox.snapshot_ttl', 900);

        $items = CatalogItem::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['subject_id', 'subject_type']);

        $spawned = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $id = (string) $item->subject_id;

            if ($repo->hasFresh('catalog:detail:'.$id, $ttl)) {
                $skipped++;

                continue;
            }

            // Concurrency cap: keep N slots busy while a background process
            // resolves each subject and forgets its slot when done.
            while ($this->activeSlots() >= $workers) {
                usleep($sleep);
            }

            $expires = Carbon::now()->addHour()->getTimestamp();
            Cache::put('catalog:warm-slot:'.$id, $expires, Carbon::now()->addHour());
            $list = (array) Cache::get('catalog:warm-slotlist', []);
            $list[$id] = $expires;
            Cache::put('catalog:warm-slotlist', $list, Carbon::now()->addHour());

            @shell_exec(sprintf(
                'nohup %s artisan catalog:detail-warm %s %d > /dev/null 2>&1 &',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($id),
                (int) $item->subject_type
            ));
            $spawned++;
        }

        // Let the remaining children finish before this scheduler run returns.
        while ($this->activeSlots() > 0) {
            usleep($sleep);
        }

        $this->info("Warmed {$spawned} (skipped {$skipped} fresh) detail snapshots.");

        return 0;
    }

    private function activeSlots(): int
    {
        $list = (array) Cache::get('catalog:warm-slotlist', []);
        $now = time();
        $active = 0;
        foreach ($list as $id => $expires) {
            if ((int) $expires > $now) {
                $active++;
            } else {
                Cache::forget('catalog:warm-slot:'.$id);
            }
        }

        return $active;
    }
}