<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\CatalogSnapshot;
use App\Support\ContentFilter;
use Illuminate\Console\Command;
use Throwable;

class CatalogPurgeBlocked extends Command
{
    protected $signature = 'catalog:purge-blocked {--dry-run : Only report, do not delete.}';

    protected $description = 'Delete persisted adult/porn titles (ContentFilter::isBlocked) and their detail snapshots.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $removed = 0;
        CatalogItem::query()
            ->chunkById(200, function ($items) use (&$removed, $dry) {
                foreach ($items as $item) {
                    if (! ContentFilter::isBlocked([
                        'subjectId' => (string) $item->subject_id,
                        'title' => $item->title,
                        'description' => $item->description,
                        'genres' => is_array($item->genres) ? $item->genres : [],
                        'imdbRating' => $item->imdb_rating,
                        'cover' => $item->cover,
                    ])) {
                        continue;
                    }

                    $removed++;
                    if ($dry) {
                        $this->line("  would remove {$item->subject_id} | {$item->title}");
                        continue;
                    }

                    try {
                        CatalogItem::query()->where('id', $item->id)->delete();
                        CatalogSnapshot::query()->where('cache_key', 'catalog:detail:'.$item->subject_id)->delete();
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        $this->info(($dry ? 'Would remove ' : 'Removed ')."{$removed} blocked titles.");

        return 0;
    }
}