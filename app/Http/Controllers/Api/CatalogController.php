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

    /** Curated landing-page rows built from the backend "operatingList". */
    public function home(): JsonResponse
    {
        $data = $this->client->home();

        $sections = [];
        foreach (($data['operatingList'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $rawItems = $entry['banner']['items'] ?? $entry['subjects'] ?? [];
            $items = ItemNormalizer::many(is_array($rawItems) ? $rawItems : []);

            if ($items === []) {
                continue;
            }

            $sections[] = [
                'title' => $entry['title'] ?? 'Featured',
                'items' => $items,
            ];
        }

        return response()->json(['data' => ['sections' => $sections]]);
    }

    /** Trending titles (page is zero-indexed upstream). */
    public function trending(Request $request): JsonResponse
    {
        $page = max(0, (int) $request->integer('page', 0));
        $perPage = min(48, max(1, (int) $request->integer('perPage', 18)));

        $data = $this->client->trending($page, $perPage);

        return response()->json([
            'data' => [
                'items' => ItemNormalizer::many($data['subjectList'] ?? $data['items'] ?? []),
                'pager' => $data['pager'] ?? null,
            ],
        ]);
    }

    /** Full search with optional type filter (all|movies|tv-series). */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:120'],
            'type' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:48'],
        ]);

        $type = SubjectType::resolve($validated['type'] ?? 'all');
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['perPage'] ?? 24);

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

    /** Health probe for the MovieBox backend connection. */
    public function diagnostics(): JsonResponse
    {
        $report = $this->client->probe();

        try {
            $home = $this->client->home();
            $report['home'] = [
                'ok' => true,
                'sections' => is_array($home['operatingList'] ?? null) ? count($home['operatingList']) : 0,
            ];
        } catch (\Throwable $e) {
            $report['home'] = ['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()];
        }

        return response()->json(['data' => $report]);
    }

    /** Autocomplete suggestions. */
    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:120'],
        ]);

        $data = $this->client->suggest($validated['q']);

        $suggestions = array_values(array_map(
            fn ($item) => [
                'word' => $item['word'] ?? null,
                'type' => (int) ($item['type'] ?? 0),
            ],
            array_filter($data['items'] ?? [], 'is_array')
        ));

        return response()->json(['data' => ['suggestions' => $suggestions]]);
    }

    /** Popular searches + editorial "hot" lists for discovery widgets. */
    public function discover(): JsonResponse
    {
        $hot = $this->client->hot();

        return response()->json([
            'data' => [
                'popular' => array_values(array_map(
                    fn ($item) => is_array($item) ? ($item['title'] ?? null) : $item,
                    $this->client->popularSearch()
                )),
                'hotMovies' => ItemNormalizer::many($hot['movie'] ?? []),
                'hotSeries' => ItemNormalizer::many($hot['tv'] ?? []),
            ],
        ]);
    }

    /**
     * Rich detail page data: best-available metadata, seasons/episodes for
     * series, cast, and recommendations. Degrades gracefully when the detail
     * HTML can't be parsed.
     */
    public function detail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subjectId' => ['required', 'string'],
            'detailPath' => ['required', 'string'],
            'subjectType' => ['sometimes', 'integer'],
            'title' => ['sometimes', 'string'],
            'cover' => ['sometimes', 'string'],
        ]);

        $subjectId = $validated['subjectId'];
        $detailPath = $validated['detailPath'];

        $detail = $this->client->detail($detailPath, $subjectId);

        $item = null;
        $seasons = [];
        $cast = [];

        if (is_array($detail)) {
            if (isset($detail['subject']) && is_array($detail['subject'])) {
                $item = ItemNormalizer::one($detail['subject']);
            }

            $seasons = $this->normalizeSeasons($detail['resource']['seasons'] ?? []);
            $cast = $this->normalizeCast($detail['stars'] ?? []);
        }

        // Fallback metadata supplied by the caller (from the listing it came from).
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
            'detailPath' => $detailPath,
            'hasResource' => true,
        ];

        $item['detailPath'] ??= $detailPath;

        $recommendations = [];
        try {
            $rec = $this->client->recommend($subjectId);
            $recommendations = ItemNormalizer::many($rec['items'] ?? []);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'data' => [
                'item' => $item,
                'isSeries' => $item['subjectType'] === SubjectType::TV_SERIES->value || $seasons !== [],
                'seasons' => $seasons,
                'cast' => $cast,
                'recommendations' => $recommendations,
                'detailAvailable' => is_array($detail),
            ],
        ]);
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
