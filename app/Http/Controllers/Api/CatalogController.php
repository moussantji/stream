<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MovieBox\MovieBoxClient;
use App\Services\MovieBox\SubjectType;
use App\Support\ItemNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(protected MovieBoxClient $client) {}

    /** Curated landing-page rows built from the tab-operating response. */
    public function home(): JsonResponse
    {
        $data = $this->client->home(0);

        $sections = [];
        foreach (($data['items'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            $rawItems = $block['subjects'] ?? [];
            if (! $rawItems && isset($block['banner']['banners'])) {
                $rawItems = $block['banner']['banners'];
            }

            $items = ItemNormalizer::many(is_array($rawItems) ? $rawItems : []);
            if ($items === []) {
                continue;
            }

            $sections[] = [
                'title' => $block['title'] ?? 'Featured',
                'items' => $items,
            ];
        }

        return response()->json(['data' => ['sections' => $sections]]);
    }

    /** Trending: a flat, de-duplicated list drawn from the landing page. */
    public function trending(Request $request): JsonResponse
    {
        $tab = SubjectType::resolve($request->input('type', 'all'));
        $tabId = match ($tab) {
            SubjectType::MOVIES => 2,
            SubjectType::TV_SERIES => 5,
            default => 0,
        };

        $data = $this->client->home($tabId);

        $seen = [];
        $items = [];
        foreach (($data['items'] ?? []) as $block) {
            foreach (ItemNormalizer::many($block['subjects'] ?? []) as $item) {
                if (! isset($seen[$item['subjectId']])) {
                    $seen[$item['subjectId']] = true;
                    $items[] = $item;
                }
            }
        }

        return response()->json(['data' => ['items' => $items, 'pager' => null]]);
    }

    /** Full search with optional type filter (all|movies|tv-series). */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:120'],
            'type' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $type = SubjectType::resolve($validated['type'] ?? 'all');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['perPage'] ?? 20);

        $data = $this->client->search($validated['q'], $type->value, $page, $perPage);

        return response()->json([
            'data' => [
                'query' => $validated['q'],
                'type' => $type->name,
                'items' => ItemNormalizer::many($data['items'] ?? []),
                'pager' => $data['pager'] ?? null,
            ],
        ]);
    }

    /** Autocomplete suggestions (backed by a small search). */
    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:120'],
        ]);

        $suggestions = [];
        try {
            $data = $this->client->search($validated['q'], 0, 1, 8);
            foreach ($data['items'] ?? [] as $item) {
                if (! empty($item['title'])) {
                    $suggestions[] = ['word' => $item['title'], 'type' => (int) ($item['subjectType'] ?? 0)];
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['data' => ['suggestions' => $suggestions]]);
    }

    /** Popular / hot lists for discovery widgets. */
    public function discover(): JsonResponse
    {
        $movies = $this->trendingItems(2);
        $series = $this->trendingItems(5);

        return response()->json([
            'data' => [
                'popular' => array_map(fn ($i) => $i['title'], array_slice($movies, 0, 10)),
                'hotMovies' => $movies,
                'hotSeries' => $series,
            ],
        ]);
    }

    /**
     * Rich detail-page data: metadata, seasons/episodes for series, cast, and
     * a few recommendations.
     */
    public function detail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subjectId' => ['required', 'string'],
            'subjectType' => ['sometimes', 'integer'],
            'title' => ['sometimes', 'string'],
            'cover' => ['sometimes', 'string'],
        ]);

        $subjectId = $validated['subjectId'];

        $detail = null;
        try {
            $detail = $this->client->itemDetails($subjectId);
        } catch (\Throwable $e) {
            report($e);
        }

        $item = is_array($detail) ? ItemNormalizer::one($detail) : null;
        $item ??= [
            'subjectId' => $subjectId,
            'subjectType' => (int) ($validated['subjectType'] ?? 0),
            'typeLabel' => SubjectType::resolve($validated['subjectType'] ?? 0)->label(),
            'title' => $validated['title'] ?? 'Untitled',
            'description' => null,
            'cover' => $validated['cover'] ?? null,
            'genres' => [],
            'releaseDate' => null,
            'year' => null,
            'durationSeconds' => null,
            'imdbRating' => null,
            'country' => null,
            'seasonCount' => null,
            'detailPath' => null,
            'hasResource' => true,
        ];

        $isSeries = $item['subjectType'] === SubjectType::TV_SERIES->value
            || (int) ($item['seasonCount'] ?? 0) > 0;

        $seasons = [];
        if ($isSeries) {
            try {
                $seasonData = $this->client->seasonInfo($subjectId);
                $seasons = $this->normalizeSeasons($seasonData['seasons'] ?? []);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $cast = is_array($detail) ? $this->normalizeCast($detail['staffList'] ?? []) : [];

        // No dedicated recommendation endpoint in the mobile API; surface a few
        // titles that share the primary genre instead.
        $recommendations = [];
        if (! empty($item['genres'][0])) {
            try {
                $rec = $this->client->search($item['genres'][0], $item['subjectType'], 1, 12);
                $recommendations = array_values(array_filter(
                    ItemNormalizer::many($rec['items'] ?? []),
                    fn ($r) => $r['subjectId'] !== $item['subjectId']
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'data' => [
                'item' => $item,
                'isSeries' => $isSeries || $seasons !== [],
                'seasons' => $seasons,
                'cast' => $cast,
                'recommendations' => $recommendations,
                'detailAvailable' => is_array($detail),
            ],
        ]);
    }

    /** Health probe for the MovieBox backend connection. */
    public function diagnostics(): JsonResponse
    {
        return response()->json(['data' => $this->client->probe()]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function trendingItems(int $tabId): array
    {
        try {
            $data = $this->client->home($tabId);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $seen = [];
        $items = [];
        foreach (($data['items'] ?? []) as $block) {
            foreach (ItemNormalizer::many($block['subjects'] ?? []) as $item) {
                if (! isset($seen[$item['subjectId']])) {
                    $seen[$item['subjectId']] = true;
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<int,mixed>  $seasons
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeSeasons(array $seasons): array
    {
        $out = [];
        foreach ($seasons as $season) {
            if (! is_array($season)) {
                continue;
            }

            $se = (int) ($season['se'] ?? 0);
            $maxEp = (int) ($season['maxEp'] ?? 0);

            $resolutions = array_values(array_unique(array_map(
                fn ($r) => (int) ($r['resolution'] ?? 0),
                array_filter($season['resolutions'] ?? [], 'is_array')
            )));
            rsort($resolutions);

            $out[] = [
                'season' => $se,
                'episodeCount' => $maxEp,
                'episodes' => $maxEp > 0 ? range(1, $maxEp) : [],
                'resolutions' => array_values(array_filter($resolutions)),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $stars
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeCast(array $stars): array
    {
        return array_values(array_map(
            fn ($star) => [
                'name' => $star['name'] ?? null,
                'character' => $star['character'] ?? null,
                'avatar' => $star['avatarUrl'] ?? null,
            ],
            array_filter($stars, 'is_array')
        ));
    }
}
