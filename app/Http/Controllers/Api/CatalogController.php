<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\CatalogSnapshot;
use App\Services\Catalog\CatalogRepository;
use App\Services\MovieBox\MovieBoxClient;
use App\Services\MovieBox\SubjectType;
use App\Support\ContentFilter;
use App\Support\ItemNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    /** Category slug -> tab-operating tab id + display title. */
    protected const CATEGORIES = [
        'films' => ['tab' => 2, 'title' => 'Films'],
        'series' => ['tab' => 5, 'title' => 'Séries & Émissions'],
        'emissions' => ['tab' => 5, 'title' => 'Séries & Émissions'],
        'animation' => ['tab' => 8, 'title' => 'Animation'],
        'anime' => ['tab' => 8, 'title' => 'Animation'],
    ];

    public function __construct(
        protected MovieBoxClient $client,
        protected CatalogRepository $repo,
    ) {}

    // -----------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------

    /** French-oriented home rows (persisted to MySQL, stale-if-error). */
    public function home(): JsonResponse
    {
        $data = $this->repo->remember(
            'catalog:home',
            $this->ttl(),
            fn () => ['sections' => $this->buildHomeSections()],
        );

        // Filter at serve time (cache stores unfiltered data) so admin blocklist
        // / keyword changes hide titles immediately.
        $sections = [];
        foreach (($data['sections'] ?? []) as $section) {
            $items = ContentFilter::apply($section['items'] ?? []);
            if ($items !== []) {
                $section['items'] = $items;
                $sections[] = $section;
            }
        }
        $data['sections'] = $sections;

        return response()->json(['data' => $data]);
    }

    /** Trending / "les plus regardés" row + page (paginated). */
    public function trending(Request $request): JsonResponse
    {
        $type = SubjectType::resolve($request->input('type', 'all'));
        $page = max(1, (int) $request->input('page', 1));
        $query = (string) config('moviebox.trending_query', 'français');

        $data = $this->repo->remember(
            "catalog:trending:{$type->value}:$page",
            $this->ttl(),
            function () use ($query, $type, $page) {
                try {
                    $res = $this->client->search($query, $type->value, $page, 20);

                    return [
                        'items' => ItemNormalizer::many($res['items'] ?? []),
                        'pager' => $this->pager($res, $page, 20),
                    ];
                } catch (\Throwable $e) {
                    report($e);

                    return ['items' => [], 'pager' => ['page' => $page, 'hasMore' => false]];
                }
            },
        );

        $data['items'] = ContentFilter::apply($data['items'] ?? []);

        return response()->json(['data' => $data]);
    }

    /** Category browse (films / séries / animation) from tab-operating (paginated). */
    public function category(Request $request): JsonResponse
    {
        $slug = strtolower((string) $request->input('tab', 'films'));
        $config = self::CATEGORIES[$slug] ?? self::CATEGORIES['films'];
        $page = max(1, (int) $request->input('page', 1));

        $data = $this->repo->remember(
            "catalog:category:{$config['tab']}:$page",
            $this->ttl(),
            fn () => ['title' => $config['title']] + $this->tabPage($config['tab'], $page),
        );

        // Title is static; ensure it is present even when served from an old snapshot.
        $data['title'] = $config['title'];
        $data['items'] = ContentFilter::apply($data['items'] ?? []);

        return response()->json(['data' => $data]);
    }

    /** Live TV channels, extracted from the landing page's liveList (best-effort). */
    public function channels(): JsonResponse
    {
        $data = $this->repo->remember(
            'catalog:channels',
            $this->ttl(),
            fn () => ['channels' => $this->buildChannels()],
        );

        return response()->json(['data' => $data]);
    }

    /** Browse the locally persisted catalog (works even if the API is down). */
    public function local(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $type = SubjectType::resolve($request->input('type', 'all'));
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 40;

        try {
            $query = CatalogItem::query();

            if ($type !== SubjectType::ALL) {
                $query->where('subject_type', $type->value);
            }
            if ($q !== '') {
                $query->where('title', 'like', '%'.$q.'%');
            }

            $total = (clone $query)->count();
            $records = $query->orderByDesc('updated_at')->forPage($page, $perPage)->get();
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['data' => ['items' => [], 'total' => 0, 'pager' => null]]);
        }

        $items = $records->map(function (CatalogItem $r) {
            $payload = is_array($r->payload) ? $r->payload : [];

            return $payload + [
                'subjectId' => $r->subject_id,
                'subjectType' => $r->subject_type,
                'typeLabel' => SubjectType::resolve($r->subject_type)->label(),
                'title' => $r->title,
                'cover' => $r->cover,
                'year' => $r->year,
                'imdbRating' => $r->imdb_rating,
                'genres' => $r->genres ?? [],
                'detailPath' => $r->detail_path,
            ];
        })->values()->all();

        return response()->json(['data' => [
            'items' => ContentFilter::apply($items),
            'total' => $total,
            'pager' => ['page' => $page, 'perPage' => $perPage, 'hasMore' => $page * $perPage < $total],
        ]]);
    }

    /** Full search (also persisted for resilience). */
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
        $q = $validated['q'];

        $data = $this->repo->remember(
            'catalog:search:'.md5("$q|{$type->value}|$page|$perPage"),
            $this->ttl(),
            function () use ($q, $type, $page, $perPage) {
                $res = $this->client->search($q, $type->value, $page, $perPage);

                return [
                    'query' => $q,
                    'type' => $type->name,
                    // NOTE: search is intentionally NOT content-filtered.
                    'items' => ItemNormalizer::many($res['items'] ?? []),
                    'pager' => $this->pager($res, $page, $perPage),
                ];
            },
        );

        return response()->json(['data' => $data]);
    }

    /** Autocomplete suggestions (not persisted — cheap + volatile). */
    public function suggest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:120'],
        ]);

        $suggestions = [];
        try {
            $data = $this->client->search($validated['q'], 0, 1, 12);
            foreach ($data['items'] ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $normalized = ItemNormalizer::one($item);
                if (empty($normalized['title']) || $normalized['title'] === 'Untitled') {
                    continue;
                }
                // Keep hentai/adult (blocked keywords) out of autocomplete.
                if (ContentFilter::isBlocked($normalized)) {
                    continue;
                }
                $suggestions[] = ['word' => $normalized['title'], 'type' => (int) ($normalized['subjectType'] ?? 0)];
                if (count($suggestions) >= 8) {
                    break;
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
        $data = $this->repo->remember('catalog:discover', $this->ttl(), function () {
            return [
                'hotMovies' => $this->flattenTab(2),
                'hotSeries' => $this->flattenTab(5),
            ];
        });

        $data['hotMovies'] = ContentFilter::apply($data['hotMovies'] ?? []);
        $data['hotSeries'] = ContentFilter::apply($data['hotSeries'] ?? []);
        $data['popular'] = array_map(fn ($i) => $i['title'], array_slice($data['hotMovies'], 0, 10));

        return response()->json(['data' => $data]);
    }

    /** Rich detail-page data (persisted per subject for resilience). */
    public function detail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subjectId' => ['required', 'string'],
            'subjectType' => ['sometimes', 'integer'],
            'title' => ['sometimes', 'string'],
            'cover' => ['sometimes', 'string'],
        ]);

        // Debug bypasses the cache and includes a probe of the raw detail keys
        // so the real trailer field can be identified.
        if ($request->boolean('debug')) {
            return response()->json(['data' => $this->buildDetail($validated, true)]);
        }

        $data = $this->repo->remember(
            'catalog:detail:'.$validated['subjectId'],
            $this->ttl(),
            fn () => $this->buildDetail($validated),
            isEmpty: fn ($d) => empty($d['item']),
        );

        if (! empty($data['recommendations'])) {
            $data['recommendations'] = ContentFilter::apply($data['recommendations']);
        }

        return response()->json(['data' => $data]);
    }

    /** Health probe for the MovieBox backend connection + local storage stats. */
    public function diagnostics(Request $request): JsonResponse
    {
        $report = $this->client->probe();

        try {
            $report['storage'] = [
                'items' => CatalogItem::count(),
                'snapshots' => CatalogSnapshot::count(),
                'persistItems' => (bool) config('moviebox.persist_items', true),
            ];
        } catch (\Throwable $e) {
            $report['storage'] = ['error' => $e->getMessage()];
        }

        // Live streaming probe for a specific title: shows the raw upstream
        // `resource` + `play-info` responses so we can see *why* a title has no
        // stream (empty list, upstream error, IP/region gate, token failure…).
        // Usage: /api/diagnostics?subjectId=XXXX[&season=1&episode=1]
        if (($subjectId = trim((string) $request->query('subjectId', ''))) !== '') {
            $report['stream'] = $this->probeStream(
                $subjectId,
                (int) $request->query('season', 0),
                (int) $request->query('episode', 0)
            );
        }

        return response()->json(['data' => $report]);
    }

    /**
     * Probe the streaming pipeline for one title and summarise the raw upstream
     * responses (kept small: counts, flags, first error) for diagnosis.
     *
     * @return array<string,mixed>
     */
    protected function probeStream(string $subjectId, int $season, int $episode): array
    {
        $out = ['subjectId' => $subjectId, 'season' => $season, 'episode' => $episode];

        // 1) resource endpoint (downloadable MP4 files, all seasons/episodes).
        try {
            $res = $this->client->resource($subjectId, 1080, 1, 20);
            $list = is_array($res['list'] ?? null) ? $res['list'] : [];
            $withLink = 0;
            $sample = [];
            foreach ($list as $it) {
                if (! is_array($it)) {
                    continue;
                }
                $hasLink = ! empty($it['resourceLink']);
                if ($hasLink) {
                    $withLink++;
                }
                if (count($sample) < 6) {
                    $sample[] = [
                        'se' => $it['se'] ?? null,
                        'ep' => $it['ep'] ?? null,
                        'resolution' => $it['resolution'] ?? null,
                        'codec' => $it['codecName'] ?? null,
                        'hasLink' => $hasLink,
                    ];
                }
            }
            $out['resource'] = [
                'ok' => true,
                'listCount' => count($list),
                'withResourceLink' => $withLink,
                'hasMore' => (bool) ($res['pager']['hasMore'] ?? false),
                'sample' => $sample,
            ];
        } catch (\Throwable $e) {
            $out['resource'] = ['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()];
            // Capture the raw upstream response to reveal the 406 source, and
            // probe every host to detect a per-host "find no content".
            try {
                $out['resource']['raw'] = $this->client->rawResourceProbe($subjectId);
                $out['resource']['freshDevice'] = $this->client->probeFreshDevice($subjectId);
            } catch (\Throwable $e2) {
                $out['resource']['rawError'] = $e2->getMessage();
            }
        }

        // 2) play-info endpoint (adaptive DASH/HLS streams).
        try {
            $info = $this->client->playInfo($subjectId, $season, $episode);
            $streams = [];
            $raw = is_array($info) ? ($info['streams'] ?? $info['list'] ?? []) : [];
            if (is_array($raw)) {
                foreach ($raw as $s) {
                    if (! is_array($s)) {
                        continue;
                    }
                    $streams[] = [
                        'format' => $s['format'] ?? null,
                        'resolution' => $s['resolutions'] ?? $s['resolution'] ?? null,
                        'codec' => $s['codecName'] ?? null,
                        'hasUrl' => ! empty($s['url']),
                        'hasSignCookie' => ! empty($s['signCookie']),
                    ];
                }
            }
            $out['playInfo'] = ['ok' => true, 'streamCount' => count($streams), 'streams' => $streams];
        } catch (\Throwable $e) {
            $out['playInfo'] = ['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()];
            try {
                $out['playInfo']['raw'] = $this->client->rawPlayInfoProbe($subjectId, $season, $episode);
            } catch (\Throwable $e2) {
                $out['playInfo']['rawError'] = $e2->getMessage();
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Builders
    // -----------------------------------------------------------------

    protected function ttl(): int
    {
        return (int) config('moviebox.snapshot_ttl', 900);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function buildHomeSections(): array
    {
        $queries = (array) config('moviebox.home_queries', []);
        $sections = [];

        foreach ($queries as $q) {
            $items = $this->searchItems($q['query'], 0, 20);
            if ($items !== []) {
                $sections[] = ['title' => $q['label'], 'items' => $items];
            }
        }

        return $sections !== [] ? $sections : $this->landingPageSections();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function searchItems(string $query, int $subjectType, int $perPage): array
    {
        try {
            $data = $this->client->search($query, $subjectType, 1, $perPage);

            // Unfiltered here; discovery surfaces apply ContentFilter at serve time.
            return ItemNormalizer::many($data['items'] ?? []);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Flatten all subjects of a tab-operating tab (page 1) into a filtered,
     * de-duplicated list.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function flattenTab(int $tabId): array
    {
        return $this->tabPage($tabId, 1)['items'];
    }

    /**
     * One paginated page of a tab-operating tab.
     *
     * @return array{items:array<int,array<string,mixed>>,pager:array{page:int,hasMore:bool}}
     */
    protected function tabPage(int $tabId, int $page): array
    {
        try {
            $data = $this->client->home($tabId, $page);
        } catch (\Throwable $e) {
            report($e);

            return ['items' => [], 'pager' => ['page' => $page, 'hasMore' => false]];
        }

        $seen = [];
        $items = [];
        $rawCount = 0;
        foreach (($data['items'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            $subjects = is_array($block['subjects'] ?? null) ? $block['subjects'] : [];
            $rawCount += count($subjects);
            foreach (ItemNormalizer::many($subjects) as $item) {
                if (! isset($seen[$item['subjectId']])) {
                    $seen[$item['subjectId']] = true;
                    $items[] = $item;
                }
            }
        }

        // hasMore heuristic: a non-trivial number of raw subjects on this page
        // suggests the next page likely has more (the tab endpoint exposes no
        // reliable total).
        return [
            'items' => $items, // filtered at serve time
            'pager' => ['page' => $page, 'hasMore' => $rawCount >= 10],
        ];
    }

    /**
     * Normalise a raw API response's pager into {page, hasMore}.
     *
     * @param  array<string,mixed>  $res
     * @return array{page:int,hasMore:bool}
     */
    protected function pager(array $res, int $page, int $perPage): array
    {
        $raw = is_array($res['pager'] ?? null) ? $res['pager'] : [];
        $count = is_array($res['items'] ?? null) ? count($res['items']) : 0;

        $hasMore = $raw['hasMore']
            ?? $raw['hasNext']
            ?? (isset($raw['nextPage']) ? (bool) $raw['nextPage'] : null)
            ?? ($count >= $perPage);

        return ['page' => $page, 'hasMore' => (bool) $hasMore];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function landingPageSections(): array
    {
        try {
            $data = $this->client->home(0);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

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
            if ($items !== []) {
                $sections[] = ['title' => $block['title'] ?? 'Featured', 'items' => $items];
            }
        }

        return $sections;
    }

    /**
     * Extract live-TV channels from the landing page's liveList entries.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function buildChannels(): array
    {
        try {
            $data = $this->client->home(0);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $channels = [];
        $seen = [];
        foreach (($data['items'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            foreach ((array) ($block['liveList'] ?? []) as $entry) {
                $channel = $this->normalizeChannel(is_array($entry) ? $entry : []);
                if ($channel && ! isset($seen[$channel['id']])) {
                    $seen[$channel['id']] = true;
                    $channels[] = $channel;
                }
            }
        }

        return $channels;
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>|null
     */
    protected function normalizeChannel(array $raw): ?array
    {
        $title = $raw['title'] ?? $raw['name'] ?? $raw['channelName'] ?? $raw['channelTitle'] ?? null;
        if (! $title) {
            return null;
        }

        $cover = null;
        foreach ([$raw['cover'] ?? null, $raw['icon'] ?? null, $raw['image'] ?? null, $raw['logo'] ?? null, $raw['poster'] ?? null] as $c) {
            if (is_array($c) && ! empty($c['url'])) {
                $cover = $c['url'];
                break;
            }
            if (is_string($c) && $c !== '') {
                $cover = $c;
                break;
            }
        }

        $url = $raw['url'] ?? $raw['playUrl'] ?? $raw['streamUrl'] ?? $raw['m3u8'] ?? $raw['hls'] ?? null;
        if (is_array($url)) {
            $url = $url['url'] ?? $url['playUrl'] ?? null;
        }

        return [
            'id' => (string) ($raw['id'] ?? $raw['channelId'] ?? $raw['subjectId'] ?? md5($title)),
            'title' => $title,
            'cover' => $cover,
            'url' => is_string($url) ? $url : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    protected function buildDetail(array $validated, bool $debug = false): array
    {
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
            // Use season-info for the full episode list (same as the provider's
            // own web player): some episodes are only available as adaptive
            // streams (play-info), not as downloadable files (resource), so the
            // list must not be limited to the downloadable ones.
            try {
                $seasonData = $this->client->seasonInfo($subjectId);
                $seasons = $this->normalizeSeasons($seasonData['seasons'] ?? []);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $cast = is_array($detail) ? $this->normalizeCast($detail['staffList'] ?? []) : [];
        $dubs = $this->ensureFrenchVersion(
            is_array($detail) ? $this->normalizeDubs($detail['dubs'] ?? []) : [],
            $item
        );

        $recommendations = [];
        if (! empty($item['genres'][0])) {
            $recommendations = array_values(array_filter(
                $this->searchItems($item['genres'][0], $item['subjectType'], 12),
                fn ($r) => $r['subjectId'] !== $item['subjectId']
            ));
        }

        $result = [
            'item' => $item,
            'isSeries' => $isSeries || $seasons !== [],
            'seasons' => $seasons,
            'cast' => $cast,
            'dubs' => $dubs,
            'trailer' => is_array($detail) ? $this->extractTrailer($detail) : null,
            'recommendations' => $recommendations,
            'detailAvailable' => is_array($detail),
        ];

        if ($debug && is_array($detail)) {
            $result['_debug'] = [
                'detailKeys' => array_keys($detail),
                'trailer' => $detail['trailer'] ?? null,
                'preVideoAddress' => $detail['preVideoAddress'] ?? null,
                'preVideoCover' => $detail['preVideoCover'] ?? null,
                'stills' => array_slice((array) ($detail['stills'] ?? []), 0, 2),
                'extractedTrailer' => $result['trailer'],
            ];
        }

        return $result;
    }

    /**
     * Best-effort trailer URL extraction from the raw itemDetails payload.
     * (The exact field varies; this checks the common shapes then searches.)
     */
    protected function extractTrailer(array $detail): ?string
    {
        $subject = is_array($detail['subject'] ?? null) ? $detail['subject'] : [];

        // Ordered candidates: the preview clip first (preVideoAddress), then the
        // trailer field(s). Each may be a string URL or a nested object/list.
        foreach ([
            $detail['preVideoAddress'] ?? null,
            $subject['preVideoAddress'] ?? null,
            $detail['trailer'] ?? null,
            $subject['trailer'] ?? null,
            $detail['trailerUrl'] ?? null,
            $detail['previewVideo'] ?? null,
            $detail['trailers'] ?? null,
            $detail['trailerList'] ?? null,
        ] as $node) {
            $url = $this->deepUrl($node);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /** Find the first http(s) URL inside a string / object / list node. */
    protected function deepUrl(mixed $node, int $depth = 0): ?string
    {
        if (is_string($node)) {
            return preg_match('~^https?://~i', $node) ? $node : null;
        }
        if (! is_array($node) || $depth > 4) {
            return null;
        }

        // Prefer explicit URL-bearing keys.
        foreach (['url', 'playUrl', 'videoAddress', 'address', 'videoUrl', 'link', 'm3u8', 'hlsUrl', 'mp4', 'src'] as $f) {
            if (! empty($node[$f]) && is_string($node[$f]) && preg_match('~^https?://~i', $node[$f])) {
                return $node[$f];
            }
        }

        foreach ($node as $value) {
            $url = $this->deepUrl($value, $depth + 1);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }



    // -----------------------------------------------------------------
    // Normalizers
    // -----------------------------------------------------------------

    /**
     * @param  array<int,array<string,mixed>>  $dubs
     * @param  array<string,mixed>  $item
     * @return array<int,array<string,mixed>>
     */
    protected function ensureFrenchVersion(array $dubs, array $item): array
    {
        foreach ($dubs as $dub) {
            if (str_starts_with($dub['code'] ?? '', 'fr') || stripos($dub['label'] ?? '', 'fran') !== false) {
                return $dubs;
            }
        }

        if (empty($item['title']) || empty($item['subjectId'])) {
            return $dubs;
        }

        $vf = $this->findFrenchVersion((string) $item['title'], (string) $item['subjectId']);
        if ($vf === null) {
            return $dubs;
        }

        if ($dubs === []) {
            $dubs[] = ['subjectId' => (string) $item['subjectId'], 'label' => 'Original', 'code' => '', 'original' => true];
        }
        $dubs[] = $vf;

        return $dubs;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function findFrenchVersion(string $title, string $excludeSubjectId): ?array
    {
        $clean = trim(preg_replace('/[\[\(].*?[\]\)]/u', '', $title)) ?: $title;

        foreach ([$clean.' version française', $clean.' français'] as $query) {
            try {
                $res = $this->client->search($query, 0, 1, 10);
            } catch (\Throwable $e) {
                report($e);

                continue;
            }

            foreach ($res['items'] ?? [] as $found) {
                $sid = (string) ($found['subjectId'] ?? '');
                $t = mb_strtolower((string) ($found['title'] ?? ''));

                if ($sid !== '' && $sid !== $excludeSubjectId
                    && (str_contains($t, 'française') || str_contains($t, 'francaise') || str_contains($t, 'version fr'))) {
                    return ['subjectId' => $sid, 'label' => 'Français (VF)', 'code' => 'fr', 'original' => false];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int,mixed>  $dubs
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeDubs(array $dubs): array
    {
        $out = [];
        foreach ($dubs as $dub) {
            if (! is_array($dub) || empty($dub['subjectId'])) {
                continue;
            }

            $label = (string) ($dub['lanName'] ?? '');
            if (stripos($label, 'original') === 0 || $label === '') {
                $label = 'Original';
            } else {
                $label = trim(str_ireplace('dub', '', $label));
            }

            $out[] = [
                'subjectId' => (string) $dub['subjectId'],
                'label' => $label,
                'code' => strtolower((string) ($dub['lanCode'] ?? '')),
                'original' => (bool) ($dub['original'] ?? false),
            ];
        }

        return $out;
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
