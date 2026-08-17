<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\StreamController;
use App\Models\CatalogItem;
use Illuminate\Console\Command;
use Throwable;

class StreamWarm extends Command
{
    protected $signature = 'stream:warm {--limit=12}';

    protected $description = 'Pre-resolve and cache play payloads for the most recent catalog items so first playback is instant.';

    public function handle(StreamController $controller): int
    {
        $limit = max(1, min(50, (int) $this->option('limit')));

        $items = CatalogItem::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['subject_id', 'subject_type', 'title']);

        if ($items->isEmpty()) {
            $this->warn('Catalog is empty — nothing to warm.');

            return 0;
        }

        $ok = 0;
        foreach ($items as $item) {
            try {
                $controller->warmPlay((string) $item->subject_id, (int) $item->subject_type);
                $ok++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Warmed {$ok}/{$items->count()} play payloads.");

        return 0;
    }
}
