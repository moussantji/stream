<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Models\CatalogSnapshot;
use Closure;
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

        try {
            $data = $fresh();

            if (! $isEmpty($data)) {
                $this->store($key, $data);
                $this->persistItems($data);

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
        }
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
