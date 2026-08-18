<?php

namespace App\Services\Catalog;

use App\Jobs\RebuildSnapshot;
use App\Models\CatalogItem;
use App\Models\CatalogSnapshot;
use App\Support\ContentFilter;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Persistent, resilient store for catalog data.
 *
 * remember() serves a fresh DB snapshot when it is younger than the TTL,
 * otherwise it calls the API. On API success the snapshot is refreshed; on API
 * failure (or an empty result) the last good snapshot from MySQL is returned so
 * the site keeps working instead of erroring out.
 */
class CatalogRepository
{
    /**
     * @param  Closure():mixed  $fresh       Fetches fresh data from the API.
     * @param  null|Closure(mixed):bool  $isEmpty  Decides whether a result is "empty" (not worth storing).
     */
    public function remember(string $key, int $ttl, Closure $fresh, ?Closure $isEmpty = null): mixed
    {
        $isEmpty ??= fn ($data) => $this->looksEmpty($data);

        $snapshot = $this->find($key);

        if ($snapshot && $this->isFresh($snapshot, $ttl)) {
            return json_decode($snapshot->payload, true);
        }

        // Stale snapshot: serve it immediately and rebuild in the background —
        // the visitor never waits for the slow upstream API. Only rebuildable
        // keys (home / trending / discover / channels) take this path; when
        // the queue is unavailable we fall through to the synchronous rebuild.
        if ($snapshot && app(SnapshotRebuilder::class)->canRebuild($key)) {
            try {
                if (Cache::add('snapshot:queued:'.$key, true, 300)) {
                    RebuildSnapshot::dispatch($key, $ttl);
                }

                return json_decode($snapshot->payload, true);
            } catch (\Throwable $e) {
                // Queue unusable (no jobs table, driver misconfig…) — fall
                // back to a synchronous refresh so data still renews.
                report($e);
            }
        }

        // Stampede guard: only one worker rebuilds an expired key. The others
        // serve the last good snapshot immediately instead of piling onto the
        // upstream API (which would saturate the PHP-FPM pool when it stalls).
        try {
            $lock = Cache::lock('snapshot:refresh:'.$key, 120);
            if (! $lock->get()) {
                if ($snapshot) {
                    return json_decode($snapshot->payload, true);
                }
            }
        } catch (Throwable $e) {
            // No lock store available — proceed without the guard.
            $lock = null;
        }

        try {
            $data = $fresh();

            if (! $isEmpty($data)) {
                $this->store($key, $data);
                $this->persistItems($data);

                $lock?->release();

                return $data;
            }

            // Empty/degraded result: prefer the last good snapshot if we have one.
            if ($snapshot) {
                return json_decode($snapshot->payload, true);
            }

            return $data;
        } catch (Throwable $e) {
            report($e);

            if ($snapshot) {
                return json_decode($snapshot->payload, true);
            }

            throw $e;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Whether a fresh snapshot exists for the key (used to skip rebuilding).
     */
    public function hasFresh(string $key, int $ttl): bool
    {
        $snapshot = $this->find($key);

        return $snapshot !== null && $this->isFresh($snapshot, $ttl);
    }

    /** Any stored payload for the key (fresh or stale) — null when absent. */
    public function snapshotPayload(string $key): ?array
    {
        $snapshot = $this->find($key);

        return $snapshot ? json_decode($snapshot->payload, true) : null;
    }

    /** Drop a snapshot (used when a rebuild must never poison the cache). */
    public function deleteKey(string $key): void
    {
        try {
            CatalogSnapshot::where('cache_key', $key)->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Whether a fresh snapshot exists (no payload decode — detail fast path). */
    public function isFreshSnapshot(string $key, int $ttl): bool
    {
        return $this->hasFresh($key, $ttl);
    }

    /** Write a snapshot directly (no freshness semantics of its own). */
    public function storeRaw(string $key, mixed $data, int $ttl = 0): void
    {
        $this->store($key, $data);
    }

    /** Persist every item found in a payload (background rebuilds). */
    public function persistPayload(mixed $data): void
    {
        $this->persistItems($data);
    }

    protected function find(string $key): ?CatalogSnapshot
    {
        try {
            return CatalogSnapshot::where('cache_key', $key)->first();
        } catch (Throwable $e) {
            // e.g. table not migrated yet — degrade to no-cache mode.
            report($e);

            return null;
        }
    }

    protected function isFresh(CatalogSnapshot $snapshot, int $ttl): bool
    {
        return $ttl > 0
            && $snapshot->updated_at !== null
            && $snapshot->updated_at->gt(now()->subSeconds($ttl));
    }

    protected function store(string $key, mixed $data): void
    {
        try {
            CatalogSnapshot::updateOrCreate(
                ['cache_key' => $key],
                ['payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Public entry point to persist an explicit list of normalized items
     * (used by the catalog:import command).
     *
     * @param  array<int,array<string,mixed>>  $items
     */
    public function saveItems(array $items): void
    {
        $this->persistItems(['items' => $items]);
    }

    /**
     * Persist every individual item found anywhere in a response payload into
     * the catalog_items table (upsert by subject_id).
     */
    protected function persistItems(mixed $data): void
    {
        if (! config('moviebox.persist_items', true)) {
            return;
        }

        $items = [];
        $this->collectItems($data, $items);

        if ($items === []) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($items as $item) {
            // Never persist adult/porn content, mirroring moviebox.ph whose own
            // surfaces are already clean upstream: blocked titles can no longer
            // appear on any page, search or recommendation.
            if (ContentFilter::isBlocked($item)) {
                continue;
            }

            $rows[] = [
                'subject_id' => (string) $item['subjectId'],
                'subject_type' => (int) ($item['subjectType'] ?? 0),
                'title' => $this->truncate($item['title'] ?? null, 255),
                'cover' => $item['cover'] ?? null,
                'description' => $item['description'] ?? null,
                'year' => is_numeric($item['year'] ?? null) ? (int) $item['year'] : null,
                'imdb_rating' => is_numeric($item['imdbRating'] ?? null) ? (float) $item['imdbRating'] : null,
                'country' => $this->truncate($item['country'] ?? null, 255),
                'duration_seconds' => is_numeric($item['durationSeconds'] ?? null) ? (int) $item['durationSeconds'] : null,
                'genres' => isset($item['genres']) ? json_encode($item['genres'], JSON_UNESCAPED_UNICODE) : null,
                'detail_path' => $item['detailPath'] ?? null,
                'payload' => json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'seen_count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        try {
            CatalogItem::upsert(
                $rows,
                ['subject_id'],
                ['subject_type', 'title', 'cover', 'description', 'year', 'imdb_rating', 'country', 'duration_seconds', 'genres', 'detail_path', 'payload', 'updated_at'],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Recursively collect normalized items (anything with subjectId + title),
     * de-duplicated by subject id.
     *
     * @param  array<string,array<string,mixed>>  $acc
     */
    protected function collectItems(mixed $data, array &$acc): void
    {
        if (! is_array($data)) {
            return;
        }

        $id = $data['subjectId'] ?? null;
        if ((is_string($id) || is_int($id)) && (string) $id !== '' && ! empty($data['title'])) {
            $acc[(string) $id] = $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->collectItems($value, $acc);
            }
        }
    }

    protected function truncate(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr((string) $value, 0, $max);
    }

    /** A result is "empty" when its main collection has no entries. */
    protected function looksEmpty(mixed $data): bool
    {
        if (! is_array($data)) {
            return true;
        }

        foreach (['sections', 'items', 'channels'] as $key) {
            if (array_key_exists($key, $data)) {
                return empty($data[$key]);
            }
        }

        // Detail-style payloads: consider present if there's an item/title.
        if (isset($data['item'])) {
            return empty($data['item']);
        }

        return $data === [];
    }
}
