<?php

namespace App\Services\Catalog;

use App\Http\Controllers\Api\CatalogController;

/**
 * Background rebuild of an expired catalog snapshot, keyed the same way the
 * controllers address them. Only keys understood here are rebuildable — the
 * rest (search md5 keys, per-subject detail) refresh synchronously on demand.
 */
class SnapshotRebuilder
{
    public function canRebuild(string $key): bool
    {
        return str_starts_with($key, 'catalog:home:')
            || str_starts_with($key, 'catalog:trending:')
            || $key === 'catalog:discover'
            || $key === 'catalog:channels';
    }

    public function rebuild(string $key, int $ttl): void
    {
        if (! $this->canRebuild($key)) {
            return;
        }

        $controller = app(CatalogController::class);

        if (str_starts_with($key, 'catalog:home:')) {
            $page = max(1, (int) substr($key, strlen('catalog:home:')));
            $data = $controller->buildHomeSectionsWithPager($page);
        } elseif (str_starts_with($key, 'catalog:trending:')) {
            [$type, $page] = array_pad(explode(':', substr($key, strlen('catalog:trending:'))), 2, '1');
            $data = $controller->buildTrending((int) $type, max(1, (int) $page));
        } elseif ($key === 'catalog:discover') {
            $data = $controller->buildDiscover();
        } elseif ($key === 'catalog:channels') {
            $data = ['channels' => $controller->buildChannels()];
        } else {
            return;
        }

        $repo = app(CatalogRepository::class);
        $repo->storeRaw($key, $data, $ttl);
        $repo->persistPayload($data);
    }
}