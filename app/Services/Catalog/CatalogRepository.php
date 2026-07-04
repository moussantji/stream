<?php

namespace App\Services\Catalog;

use App\Models\CatalogSnapshot;
use Closure;
use Illuminate\Support\Facades\DB;
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
