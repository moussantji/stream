<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedTitle;
use App\Models\CatalogItem;
use App\Models\CatalogSnapshot;
use App\Services\Catalog\CatalogRepository;
use App\Services\AniList\AniListClient;
use App\Services\DioStream\DioStreamClient;
use App\Services\Itunes\ItunesClient;
use App\Services\Jikan\JikanClient;
use App\Services\MovieBox\MovieBoxClient;
use App\Services\MovieBox\SubjectType;
use App\Support\ContentFilter;
use App\Support\ItemNormalizer;
use App\Support\TextSanitizer;
use App\Support\VersionFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
        protected ?DioStreamClient $dio = null,
    ) {
        $this->dio ??= app(DioStreamClient::class);
    }

    /** @var AniListClient|null */
    protected ?AniListClient $anilist = null;

    /** @var JikanClient|null */
    protected ?JikanClient $jikan = null;

    /** @var ItunesClient|null */
    protected ?ItunesClient $itunes = null;

    protected function anilist(): AniListClient
    {
        return $this->anilist ??= app(AniListClient::class);
    }

    protected function jikan(): JikanClient
    {
        return $this->jikan ??= app(JikanClient::class);
    }

    protected function itunes(): ItunesClient
    {
        return $this->itunes ??= app(ItunesClient::class);
    }

    /**
     * Descending sort by API rating, then by release year (newest first).
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    protected static function rankDesc(array $a, array $b): int
    {
        $cmp = (float) ($b['imdbRating'] ?? 0) <=> (float) ($a['imdbRating'] ?? 0);

        return $cmp !== 0 ? $cmp : ((int) ($b['year'] ?? 0) <=> (int) ($a['year'] ?? 0));
    }

    /** First non-empty string, else null. */
    protected static function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------

    /** French-oriented home rows (persisted to MySQL, stale-if-error). */
    public function home(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->homeData(max(1, (int) $request->input('page', 1)))]);
    }

    /**
     * Home payload shared by the JSON API and the SSR web controller: rows
     * filtered at serve time (cache stores unfiltered data) so admin blocklist
     * / keyword changes hide titles immediately.
     *
     * @return array{sections:array<int,array<string,mixed>>,pager:array{page:int,hasMore:bool}}
     */
    public function homeData(int $page): array
    {
        try {
            $data = $this->repo->remember(
                'catalog:home:'.$page,
                $this->ttl(),
                fn () => $this->buildHomeSectionsWithPager($page),
            );
        } catch (\Throwable $e) {
            // Never an empty page: if no snapshot exists and the upstream
            // build fails outright, still return a 200 shell so the SPA
            // renders (and a background job can retry).
            report($e);

            $data = ['sections' => [], 'pager' => ['page' => $page, 'hasMore' => false]];
        }

        $target = max(10, (int) config('moviebox.home_section_size', 20));
        $sections = [];
        // Pool window for this page: page 1 pads from the top of each pool,
        // page 2 from the next block, etc. — so the padding of later pages
        // shows NEW titles instead of repeating page 1 (the client already
        // drops duplicates across pages, which emptied the rows).
        $poolOffset = ($page - 1) * $target;
        // Seed with every subject shown on the previous pages (their cached
        // snapshots), mirroring the client's cross-page dedup: feed items that
        // repeat earlier pages are dropped server-side and replaced by fresh
        // padding, so infinite-scroll rows stay full.
        $pageSeen = [];
        if ($page > 1) {
            try {
                $prior = \App\Models\CatalogSnapshot::query()
                    ->where('cache_key', 'like', 'catalog:home:%')
                    ->where('cache_key', '!=', 'catalog:home:'.$page)
                    ->get(['payload']);
                foreach ($prior as $snap) {
                    $prev = json_decode((string) $snap->payload, true);
                    foreach (($prev['sections'] ?? []) as $s) {
                        foreach (($s['items'] ?? []) as $item) {
                            $pageSeen[$item['subjectId'] ?? null] = true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        foreach (($data['sections'] ?? []) as $section) {
            $items = ContentFilter::apply($section['items'] ?? []);
            // Top-rated & recent only, straight from the provider's own fields:
            // rating >= 7 AND year >= 2024, sorted by rating then year.
            $items = array_values(array_filter($items, fn (array $item) => ContentFilter::isTopOnly($item)));
            usort($items, fn (array $a, array $b) => self::rankDesc($a, $b));
            if ($items !== []) {
                // Short editorial rows (the provider sends a handful of titles)
                // are padded up to the target size with qualified titles of the
                // same type, so every home section shows a full row.
                $section['items'] = $this->fillHomeSection($items, (string) ($section['title'] ?? ''), $target, $poolOffset, $pageSeen);
                $sections[] = $section;
            }
        }
        $data['sections'] = $sections;
        $data['pager'] = ['page' => $page, 'hasMore' => $sections !== [] && ($data['pager']['hasMore'] ?? false)];

        return $data;
    }

    /**
     * Pad a home section up to $target titles with the qualified pool of the
     * matching type (films / series / animation), de-duplicated by subject id.
     * The provider's editorial rows only carry a handful of titles, so without
     * this most home sections would render one or two cards after filtering.
     *
     * $poolOffset shifts the window into the pool so later pages pad with
     * fresh titles instead of repeating page 1; $pageSeen accumulates every
     * subject id already used on this page (feed items + padding), so two
     * sections of the same page never show the same card.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,array<string,mixed>>  $poolSeen
     * @return array<int,array<string,mixed>>
     */
    protected function fillHomeSection(array $items, string $title, int $target, int $poolOffset, array &$pageSeen): array
    {
        // Mirror the client's cross-section dedup: feed items already shown
        // earlier on this page are dropped here too, so the padding fills the
        // freed slots and the row renders full instead of leaving holes.
        $items = array_values(array_filter($items, function (array $item) use (&$pageSeen): bool {
            $id = $item['subjectId'] ?? null;
            if (isset($pageSeen[$id])) {
                return false;
            }
            $pageSeen[$id] = true;

            return true;
        }));
        if (count($items) >= $target) {
            return $items;
        }

        $lower = mb_strtolower($title);
        $isAnime = str_contains($lower, 'anim') || str_contains($lower, 'enfant') || str_contains($lower, 'dessin');
        $isSeries = ! $isAnime && (str_contains($lower, 'série') || str_contains($lower, 'serie') || str_contains($lower, 'émission') || str_contains($lower, 'emission'));

        $candidates = [];
        try {
            if ($isAnime) {
                // Anime rows keep their own curation rules: the animation pool
                // (search-based, metadata-gated) — no rating/year gate, so the
                // row can actually reach the target size. The search pool is
                // small (and sometimes empty when its last rebuild failed), so
                // it is supplemented with the persisted local anime titles —
                // qualified (rating >= 7, year >= 2024) first, then the rest,
                // matching the top-only character of the other rows.
                $pool = $this->ensureCategoryPool(SubjectType::ANIME->value, 'animation', $target * 3, false);
                foreach (ContentFilter::apply($pool['items']) as $item) {
                    if ($this->searchGate($item)) {
                        $candidates[] = $item;
                    }
                }

                try {
                    $local = CatalogItem::query()
                        ->whereNotNull('cover')
                        ->whereNotNull('payload')
                        ->where(function ($q) {
                            $q->where('subject_type', SubjectType::ANIME->value)
                                ->orWhere('title', 'like', '%anim%');
                        })
                        ->orderByDesc('imdb_rating')
                        ->limit(300)
                        ->get();
                    foreach ($local as $row) {
                        $item = json_decode((string) $row->payload, true);
                        if (! is_array($item) || empty($item['subjectId']) || ContentFilter::isBlocked($item)) {
                            continue;
                        }
                        $candidates[] = $item;
                    }
                } catch (\Throwable $e) {
                    report($e);
                }

                usort($candidates, fn (array $a, array $b) =>
                    (ContentFilter::isTopOnly($b) <=> ContentFilter::isTopOnly($a))
                    ?: ((float) ($b['imdbRating'] ?? 0) <=> (float) ($a['imdbRating'] ?? 0)));
            } elseif ($isSeries) {
                $pool = $this->ensureCategoryPool(SubjectType::TV_SERIES->value, 'series', $target * 3, true);
                $candidates = array_values(array_filter($pool['items'], fn (array $item) => ContentFilter::isTopOnly($item)));
            } else {
                $pool = $this->ensureCategoryPool(SubjectType::MOVIES->value, 'films', $target * 3, true);
                $candidates = array_values(array_filter($pool['items'], fn (array $item) => ContentFilter::isTopOnly($item)));
            }
        } catch (\Throwable $e) {
            report($e);

            $candidates = [];
        }

        // Window into the pool for this page; wraps around when exhausted so
        // a long infinite scroll still finds candidates (seen-skip prevents
        // duplicates).
        $walk = array_merge(
            array_slice($candidates, $poolOffset),
            array_slice($candidates, 0, $poolOffset)
        );

        foreach ($walk as $item) {
            if (isset($pageSeen[$item['subjectId'] ?? null])) {
                continue;
            }
            $pageSeen[$item['subjectId'] ?? null] = true;
            $items[] = $item;
            if (count($items) >= $target) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return array{sections:array<int,array<string,mixed>>,pager:array{page:int,hasMore:bool}}
     */
    public function buildHomeSectionsWithPager(int $page): array
    {
        $sections = [];
        $hasMore = false;

        if ($page === 1) {
            // Page 1 mirrors the movieboxhd.net home: the provider's own
            // editorial rows from the localized H5 web feed (operatingList),
            // in their order, with "[Version française]" titles (Moana[CAM]
            // [Version française], Toy Story 5…) instead of the mobile API's
            // "[Hindi]" tags. Falls back to the curated rows below when the
            // web feed is unreachable.
            $editorial = $this->h5EditorialSections();
            if ($editorial !== []) {
                $sections = $editorial;
                $hasMore = true;
            } else {
                $sections = $this->legacyHomeRows();
                $hasMore = $sections !== [];
            }
        } else {
            // Page N shifts each query's window so scrolling the home page
            // keeps discovering new titles instead of repeating page 1.
            // All queries run in ONE concurrent batch (searchMany), so the
            // slowest query bounds the wait instead of the sum of all six.
            $queries = (array) config('moviebox.home_queries', []);
            $batch = $this->client->searchMany(array_column($queries, 'query'), 0, 20, $page);

            foreach ($queries as $q) {
                $items = [];
                foreach (ItemNormalizer::many($batch[$q['query']]['items'] ?? []) as $item) {
                    if (! $this->searchGate($item)) {
                        continue;
                    }
                    $items[] = $item;
                }
                if ($items !== []) {
                    $sections[] = ['title' => $q['label'], 'items' => $items];
                }
                if (count($items) >= 20) {
                    $hasMore = true;
                }
            }
        }

        if ($sections === []) {
            return ['sections' => $this->landingPageSections(), 'pager' => ['page' => $page, 'hasMore' => false]];
        }

        return ['sections' => $sections, 'pager' => ['page' => $page, 'hasMore' => $hasMore]];
    }

    /**
     * The provider's own editorial home rows, in feed order (mirrors the
     * movieboxhd.net home). Only subject sections are kept — banners, custom
     * blocks (channels, shorts, music), categories and sports are skipped.
     *
     * @return array<int,array{title:string,items:array<int,array<string,mixed>>}>
     */
    protected function h5EditorialSections(): array
    {
        try {
            $data = $this->client->h5Home();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $sections = [];
        foreach (($data['operatingList'] ?? []) as $section) {
            if (! is_array($section) || ($section['type'] ?? '') !== 'SUBJECTS_MOVIE') {
                continue;
            }
            $title = trim((string) ($section['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $seen = [];
            $items = [];
            foreach (ItemNormalizer::many($section['subjects'] ?? []) as $item) {
                if (isset($seen[$item['subjectId']])) {
                    continue;
                }
                // Adult/porn keywords are never shown on home rows.
                if (ContentFilter::isBlocked($item)) {
                    continue;
                }
                // Keep French/English/VO/VOSTFR titles (quality tags such as
                // [CAM] tolerated, like the provider's own home); drop titles
                // carrying only foreign dubs (Hindi, Tamil…).
                if (! VersionFilter::acceptsForRow((string) ($item['title'] ?? ''))) {
                    continue;
                }
                // Precise adult/hentai gate via public metadata sources
                // (AniList/Jikan for anime rows, iTunes for films/series,
                // DioStream/TMDB as final fallback).
                if (! $this->metadataGuard($item, preg_match('/anim/i', $title) === 1)) {
                    continue;
                }
                $seen[$item['subjectId']] = true;
                $items[] = $item;
            }

            if ($items !== []) {
                $sections[] = ['title' => $title, 'items' => $items];
            }
        }

        return $sections;
    }

    /**
     * Anime items from the H5 feed's curated sections (titles matching
     * "anim*" plus the kids-animation rows), deduplicated, with the same
     * title-level gates as the editorial home rows.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function h5AnimeItems(): array
    {
        try {
            $data = $this->client->h5Home();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $seen = [];
        $items = [];
        foreach (($data['operatingList'] ?? []) as $section) {
            if (! is_array($section) || ($section['type'] ?? '') !== 'SUBJECTS_MOVIE') {
                continue;
            }
            $title = trim((string) ($section['title'] ?? ''));
            if ($title === '' || (preg_match('/anim/i', $title) !== 1 && ! in_array($title, ['Pour les Enfants', 'Films et dessins animés pour enfants'], true))) {
                continue;
            }

            foreach (ItemNormalizer::many($section['subjects'] ?? []) as $item) {
                if (isset($seen[$item['subjectId']])) {
                    continue;
                }
                if (ContentFilter::isBlocked($item) || ! VersionFilter::acceptsForRow((string) ($item['title'] ?? ''))) {
                    continue;
                }
                // Same metadata gate as the serve-time animation category:
                // verdicts are cached, so this only costs on first seed.
                if (! $this->animationGate($item)) {
                    continue;
                }
                $seen[$item['subjectId']] = true;
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Fallback home rows used when the H5 web feed is unreachable: popular
     * films / anime / animation / series from the upstream tabs.
     *
     * @return array<int,array{title:string,items:array<int,array<string,mixed>>}>
     */
    protected function legacyHomeRows(): array
    {
        $sections = [];

        $popFilms = $this->preciseFilter(
            $this->h5Row(['Films Tendance', 'Trending Movies']) ?: $this->tabPage(2, 1)['items'],
            requireType: 1
        );
        if ($popFilms !== []) {
            $sections[] = ['title' => 'Films populaires', 'items' => $popFilms];
        }

        $anime = $this->preciseFilter(
            $this->h5Row(['Animés populaires', 'Animes']) ?: array_map(
                fn ($i) => $this->stripVersionTag($i),
                $this->tabPage(8, 1)['items']
            ),
            requireGenre: 'animation'
        );
        if ($anime !== []) {
            $sections[] = ['title' => 'Animés & Anime', 'items' => $anime];
        }

        $animFilms = $this->preciseFilter(
            $this->h5Row(['Pour les Enfants', 'Animation']),
            requireGenre: 'animation',
            requireType: 1
        );
        if ($animFilms !== []) {
            $sections[] = ['title' => "Films d'animation", 'items' => $animFilms];
        }

        $popSeries = $this->preciseFilter(
            $this->h5Row(['Séries Tendance', 'Trending Series']) ?: $this->tabPage(5, 1)['items'],
            requireType: 2
        );
        if ($popSeries !== []) {
            $sections[] = ['title' => 'Séries populaires', 'items' => $popSeries];
        }

        return $sections;
    }

    /**
     * Remove a single "[Tag]" version suffix from a normalized item's title.
     * Only applied to the anime home row where the upstream tab is polluted
     * with "[Hindi]" labels on titles that have an original + subtitled track.
     *
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    protected function stripVersionTag(array $item): array
    {
        $title = (string) ($item['title'] ?? '');
        if ($title !== '') {
            $item['title'] = trim((string) preg_replace('/\s*\[[^\]]*\]\s*$/u', '', $title));
        }

        return $item;
    }

    /** Trending / "les plus regardés" row + page (paginated). */
    public function trending(Request $request): JsonResponse
    {
        $type = SubjectType::resolve($request->input('type', 'all'));
        $page = max(1, (int) $request->input('page', 1));

        $data = $this->repo->remember(
            "catalog:trending:{$type->value}:$page",
            $this->ttl(),
            fn () => $this->buildTrending($type->value, $page),
        );

        $data['items'] = ContentFilter::apply($data['items'] ?? []);

        return response()->json(['data' => $data]);
    }

    /**
     * Trending snapshot payload (shared by the controller and the background
     * snapshot rebuilder). Items are passed through the metadata gate so
     * hentai/porn with clean titles never reach the "plus regardés" row.
     *
     * @return array{items:array<int,array<string,mixed>>,pager:array{page:int,hasMore:bool}}
     */
    public function buildTrending(int $typeValue, int $page): array
    {
        $query = (string) config('moviebox.trending_query', 'français');

        try {
            $res = $this->client->search($query, $typeValue, $page, 20);

            return [
                'items' => array_values(ContentFilter::apply(ItemNormalizer::many($res['items'] ?? []))),
                'pager' => $this->pager($res, $page, 20),
            ];
        } catch (\Throwable $e) {
            report($e);

            return ['items' => [], 'pager' => ['page' => $page, 'hasMore' => false]];
        }
    }

    /**
     * Paginated "similar titles" for a film/series detail page (genre-based,
     * local-first, deduped against the source subject).
     *
     * @return array<string,mixed>
     */
    public function suggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subjectId' => ['required', 'string'],
            'subjectType' => ['sometimes', 'integer'],
            'genres' => ['sometimes', 'string'],
        ]);

        $page = max(1, (int) $request->input('page', 1));
        $perPage = 20;
        $excluded = (string) $validated['subjectId'];

        $genres = array_values(array_filter(array_map(
            fn ($g) => mb_strtolower(trim((string) $g)),
            explode('|', (string) ($validated['genres'] ?? ''))
        )));

        // Genre hints may be missing from the URL — fall back to the local row.
        if ($genres === []) {
            $row = CatalogItem::query()->where('subject_id', $excluded)->first();
            $genres = array_map(fn ($g) => mb_strtolower(trim((string) $g)), (array) ($row->genres ?? []));
        }

        try {
            $rows = CatalogItem::query()
                ->where('subject_id', '!=', $excluded)
                ->orderByDesc('seen_count')
                ->limit(600)
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['data' => ['items' => [], 'pager' => ['page' => $page, 'hasMore' => false]]]);
        }

        $matches = [];
        foreach ($rows as $row) {
            if ($genres === []) {
                $matches[] = $row;
                continue;
            }
            $rowGenres = array_map(fn ($g) => mb_strtolower(trim((string) $g)), (array) $row->genres);
            if (count(array_intersect($genres, $rowGenres)) > 0) {
                $matches[] = $row;
            }
        }

        $slice = array_slice($matches, ($page - 1) * $perPage, $perPage);
        $items = array_values(array_filter(array_map(
            fn ($row) => ItemNormalizer::one([
                'subjectId' => (string) $row->subject_id,
                'subjectType' => (int) $row->subject_type,
                'title' => $row->title,
                'cover' => $row->cover,
                'description' => $row->description,
                'releaseDate' => $row->release_date,
                'genres' => (array) $row->genres,
                'imdbRating' => $row->imdb_rating,
                'year' => $row->year,
                'durationSeconds' => $row->duration_seconds,
                'seasonCount' => $row->season_count,
                'detailPath' => $row->detail_path,
            ]),
            $slice
        )));

        $items = ContentFilter::apply($items);

        return response()->json([
            'data' => [
                'items' => $items,
                'pager' => ['page' => $page, 'hasMore' => $page * $perPage < count($matches)],
            ],
        ]);
    }

    /** Category browse (films / séries / animation) from tab-operating (paginated). */
    public function category(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->categoryData(
            strtolower((string) $request->input('tab', 'films')),
            max(1, (int) $request->input('page', 1)),
        )]);
    }

    /**
     * Category payload shared by the JSON API and the SSR web controller.
     * Films & series pages are single-shot: the whole qualified pool is built
     * (and cached) on the first request and returned in one response, so the
     * client never paginates. The animation tab keeps its paginated pool
     * (thousands of entries, filters client-side).
     *
     * @return array{title:string,items:array<int,array<string,mixed>>,pager:array{page:int,hasMore:bool}}
     */
    public function categoryData(string $slug, int $page): array
    {
        $config = self::CATEGORIES[$slug] ?? self::CATEGORIES['films'];
        $perPage = max(10, min(40, (int) config('moviebox.category_page_size', 20)));
        $animeHint = in_array($slug, ['animation', 'anime'], true);
        $oneShot = ! $animeHint;

        try {
            $needed = $oneShot
                ? (int) config('moviebox.category_pool_cap', 300)
                : $page * $perPage;
            $pool = $this->ensureCategoryPool($config['tab'], $slug, $needed, $oneShot);

            // Serve-time guard: drops adult/hentai items (verdicts are cached, so
            // this also purges pools built before the guard existed). The
            // animation/anime categories additionally require the metadata to
            // confirm the title really is animation, so search junk (Nollywood
            // videos, songs, wrestling clips…) never pollutes the category.
            $all = array_values(array_filter(
                ContentFilter::apply($pool['items']),
                fn (array $item) => $this->searchGate($item)
            ));

            // Films & series pages show only the provider's top-rated and
            // recent titles (rating >= 7 AND year >= 2024, on the API's own
            // fields), sorted by rating then year. The animation tab keeps
            // its own curation rules.
            if (! $animeHint) {
                $all = array_values(array_filter($all, fn (array $item) => ContentFilter::isTopOnly($item)));
                usort($all, fn (array $a, array $b) => self::rankDesc($a, $b));
            }

            $items = $oneShot ? $all : array_slice($all, ($page - 1) * $perPage, $perPage);
            $hasMore = $oneShot ? false : (count($all) > $page * $perPage || ! $pool['exhausted']);
        } catch (\Throwable $e) {
            report($e);

            $items = [];
            $hasMore = false;
        }

        return [
            'title' => $config['title'],
            'items' => $items,
            'pager' => ['page' => $page, 'hasMore' => $hasMore],
        ];
    }

    /**
     * Return the category pool, extending it with deeper search pages until it
     * holds at least $needed items (or the upstream results are exhausted).
     * When $qualifiedOnly is set, "enough" means enough items passing the
     * top-rated/recent filter, so films & series pages always fill a page.
     *
     * @return array{items:array<int,array<string,mixed>>,exhausted:bool}
     */
    protected function ensureCategoryPool(int $tabId, string $slug, int $needed, bool $qualifiedOnly = false): array
    {
        $key = "catalog:category-pool:{$tabId}";
        $maxDepth = max(1, (int) config('moviebox.category_search_depth', 5));

        $usableCount = function (array $items) use ($qualifiedOnly): int {
            if (! $qualifiedOnly) {
                return count($items);
            }

            return count(array_filter($items, fn (array $i) => ContentFilter::isTopOnly($i)));
        };

        $data = Cache::get($key);
        if (! is_array($data)) {
            $data = [
                'items' => [],
                'depth' => 0,
                // Subject type of the tab: films and animation pools are
                // movies (1), series/emissions pools are TV series (2) — the
                // upstream search matches these enum values exactly.
                'type' => in_array($slug, ['series', 'emissions'], true)
                    ? SubjectType::TV_SERIES->value
                    : SubjectType::MOVIES->value,
                'queries' => array_values(array_filter((array) config("moviebox.category_queries.$slug", []))),
                'exhausted' => false,
            ];

            // Animation/anime pools are seeded with the provider's curated
            // French-dubbed anime rows from the H5 feed, so the category
            // opens on real animation instead of search leftovers.
            if (in_array($slug, ['animation', 'anime'], true)) {
                foreach ($this->h5AnimeItems() as $item) {
                    $sid = $item['subjectId'] ?? null;
                    if ($sid === null || isset($data['seen'][$sid])) {
                        continue;
                    }
                    $data['seen'][$sid] = true;
                    $data['items'][] = $item;
                }
            }
        }

        while ($usableCount($data['items']) < $needed
            && ! $data['exhausted']
            && $data['depth'] < $maxDepth
            && empty($data['local_loaded'])) {
            $page = $data['depth'] + 1;

            // All queries of this depth run in ONE concurrent H5 batch (the
            // per-query results double as the pool's caching layer), so the
            // slowest query bounds the wait instead of the sum of all six.
            $batch = $this->client->searchMany($data['queries'], (int) $data['type'], $page, 20);

            $extended = false;
            foreach ($batch as $items) {
                foreach ($items['items'] ?? [] as $item) {
                    $sid = $item['subjectId'] ?? null;
                    if ($sid === null) {
                        continue;
                    }
                    // Normalize here so pools carry the API's rating/year
                    // (imdbRatingValue/releaseDate) and the display shape the
                    // frontend expects (coverSmall, french, displayTitle…).
                    $normalized = ItemNormalizer::one($item);
                    if ($normalized === null) {
                        continue;
                    }
                    $sid = $normalized['subjectId'];
                    if (! isset($data['seen'][$sid])) {
                        $data['seen'][$sid] = true;
                        $data['items'][] = $normalized;
                        $extended = true;
                    }
                }
            }
            $data['depth'] += 1;

            // The H5 French-queryable pool is bounded (~150 titles); extend
            // with the persisted local catalog when the pool still can't
            // satisfy the page (raw count, or qualified count when the
            // top-rated/recent filter is active).
            if ($usableCount($data['items']) < $needed && empty($data['local_loaded'])) {
                $data['local_loaded'] = true;
                $local = CatalogItem::query()
                    ->where('subject_type', $data['type'])
                    ->orderByDesc('seen_count')
                    ->limit($qualifiedOnly ? 2000 : 600)
                    ->get();
                foreach ($local as $row) {
                    $normalized = ItemNormalizer::one([
                        'subjectId' => (string) $row->subject_id,
                        'subjectType' => (int) $row->subject_type,
                        'title' => $row->title,
                        'cover' => $row->cover,
                        'description' => $row->description,
                        'genres' => $row->genres,
                        'imdbRating' => $row->imdb_rating,
                        'releaseDate' => $row->release_date,
                        'year' => $row->year,
                        'durationSeconds' => $row->duration_seconds,
                        'country' => $row->country,
                        'detailPath' => $row->detail_path,
                        'hasResource' => true,
                    ]);
                    if ($normalized === null) {
                        continue;
                    }
                    $sid = $normalized['subjectId'];
                    if (! isset($data['seen'][$sid])) {
                        $data['seen'][$sid] = true;
                        $data['items'][] = $normalized;
                        $extended = true;
                    }
                }
            }
            if (! $extended && $data['local_loaded']) {
                $data['exhausted'] = true;
            }
            Cache::put($key, $data, $this->ttl());
        }

        return $data;
    }

    /** Live TV channels, extracted from the landing page's liveList (best-effort). */
    public function channels(): JsonResponse
    {
        try {
            $data = $this->repo->remember(
                'catalog:channels',
                $this->ttl(),
                fn () => ['channels' => $this->buildChannels()],
            );
        } catch (\Throwable $e) {
            report($e);

            $data = ['channels' => []];
        }

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
            'items' => array_values(array_filter(
                ContentFilter::apply($items),
                fn (array $item) => $this->searchGate($item)
            )),
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

                $items = ContentFilter::apply(ItemNormalizer::many($res['items'] ?? []));

                return [
                    'query' => $q,
                    'type' => $type->name,
                    'items' => $items,
                    'pager' => $this->pager($res, $page, $perPage),
                ];
            },
        );

        $data['items'] = VersionFilter::apply($data['items'] ?? []);
        // Serve-time filter: the cached payload may predate the latest blocked
        // keywords / subject types, so re-run the filter on every request.
        $data['items'] = ContentFilter::apply($data['items'] ?? []);
        // Metadata gate at serve time: titles like "XXX: The Animation"
        // (mostly hentai) must be confirmed as real animation or are dropped.
        $data['items'] = array_values(array_filter($data['items'], fn (array $item) => $this->searchGate($item)));

        // Loose local fallback: the upstream search matches the query literally,
        // so "from francais" finds nothing while the title is stored as
        // "From [Version française]". When upstream yields nothing, match every
        // word of the query against the local titles (the DB collation is
        // accent- and case-insensitive, so "francais" matches "française").
        if (($data['items'] ?? []) === []) {
            $local = $this->localSearch($q, $type, $perPage * 2);
            if ($local !== []) {
                $data['items'] = $local;
                $data['pager'] = ['page' => $page, 'perPage' => $perPage, 'hasMore' => false];
            }
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Local catalog search: every query word must appear in the title (accent-
     * and case-insensitive via the DB collation), same serve-time filters as
     * the upstream search.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function localSearch(string $q, SubjectType $type, int $limit = 20): array
    {
        $words = array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($q))),
            fn (string $w) => mb_strlen($w) > 1
        ));

        if ($words === []) {
            return [];
        }

        try {
            $query = CatalogItem::query();
            if ($type !== SubjectType::ALL) {
                $query->where('subject_type', $type->value);
            }
            foreach ($words as $w) {
                $query->where('title', 'like', '%'.$w.'%');
            }
            $rows = $query->orderByDesc('seen_count')->limit($limit)->get();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $items = $rows->map(function (CatalogItem $r) {
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

        $items = VersionFilter::apply($items);
        $items = ContentFilter::apply($items);

        return array_values(array_filter($items, fn (array $item) => $this->searchGate($item)));
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
                // Only French / English / VOSTFR / VO versions.
                if (! VersionFilter::accepts($normalized['title'] ?? '', (int) ($normalized['subjectType'] ?? 0))) {
                    continue;
                }
                $suggestions[] = ['word' => $normalized['title'], 'displayTitle' => $normalized['displayTitle'] ?? $normalized['title'], 'type' => (int) ($normalized['subjectType'] ?? 0)];
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
        try {
            $data = $this->repo->remember('catalog:discover', $this->ttl(), fn () => $this->buildDiscover());
        } catch (\Throwable $e) {
            report($e);

            $data = ['hotMovies' => [], 'hotSeries' => []];
        }

        $data['hotMovies'] = ContentFilter::apply($data['hotMovies'] ?? []);
        $data['hotSeries'] = ContentFilter::apply($data['hotSeries'] ?? []);
        $data['popular'] = array_map(fn ($i) => $i['title'], array_slice($data['hotMovies'], 0, 10));

        return response()->json(['data' => $data]);
    }

    /**
     * Discovery snapshot payload (shared with the background rebuilder).
     *
     * @return array{hotMovies:array<int,array<string,mixed>>,hotSeries:array<int,array<string,mixed>>}
     */
    public function buildDiscover(): array
    {
        return [
            'hotMovies' => array_values(array_filter(
                $this->flattenTab(2),
                fn (array $item) => $this->searchGate($item)
            )),
            'hotSeries' => array_values(array_filter(
                $this->flattenTab(5),
                fn (array $item) => $this->searchGate($item)
            )),
        ];
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

        return response()->json(['data' => $this->detailData($validated)]);
    }

    /**
     * Detail payload shared by the JSON API and the SSR web controller: cached
     * full build when fresh, stale-if-error, fast local paint otherwise — all
     * gates (blocklist, adult, animation) apply on both surfaces.
     *
     * @param  array<string,mixed>  $validated
     * @return array<string,mixed>
     */
    public function detailData(array $validated): array
    {
        if (BlockedTitle::query()->where('term', $validated['subjectId'])->exists()) {
            abort(404, 'Content unavailable.');
        }

        // Debug bypasses the cache and includes a probe of the raw detail keys
        // so the real trailer field can be identified.
        if (($validated['debug'] ?? false) === true) {
            return $this->buildDetail($validated, true);
        }

        try {
            // No snapshot layer for details: always fetch live upstream so a
            // stored row can never mask real metadata (poisoned "Untitled"
            // snapshots) nor the URL-provided title/cover.
            $data = $this->buildDetail($validated);
        } catch (\Throwable $e) {
            // Never a blank page: fall back to an unavailable shell.
            report($e);

            $data = ['item' => null, 'detailAvailable' => false];
        }

        if (! empty($data['recommendations'])) {
            $data['recommendations'] = ContentFilter::apply($data['recommendations']);
        }

        // Adult/porn titles are never displayed (same as moviebox.ph, whose own
        // surfaces are clean): a deep link to one is treated as unavailable.
        if (isset($data['item']) && ContentFilter::isBlocked($data['item'])) {
            abort(404, 'Content unavailable.');
        }

        // "XXX: The Animation"-style hentai with a clean title: the metadata
        // gate must confirm real animation before the detail page opens.
        if (isset($data['item'])
            && preg_match('/anim/i', (string) ($data['item']['title'] ?? '')) === 1
            && ! $this->animationGate($data['item'])
        ) {
            abort(404, 'Content unavailable.');
        }

        return $data;
    }

    /**
     * Resolve a detail slug (detailPath) to a subjectId: local catalog first,
     * upstream H5 detail as fallback. Mapping is cached for a day.
     */
    public function resolveSubjectId(string $path): ?string
    {
        if (trim($path) === '') {
            return null;
        }

        return Cache::remember('catalog:resolve:'.md5($path), 86400, function () use ($path) {
            $sid = CatalogItem::query()->where('detail_path', $path)->value('subject_id');
            if ($sid !== null) {
                return (string) $sid;
            }

            try {
                $detail = $this->client->h5Detail($path);
                $sid = $detail['subjectId'] ?? $detail['item']['subjectId'] ?? null;

                return $sid !== null ? (string) $sid : null;
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        });
    }

    /** Resolve endpoint: slug -> subjectId for client-side navigation. */
    public function resolve(Request $request): JsonResponse
    {
        $path = trim((string) $request->input('path', ''));
        $subjectId = $this->resolveSubjectId($path);

        if ($subjectId === null) {
            abort(404, 'Unknown title.');
        }

        return response()->json(['data' => ['subjectId' => $subjectId, 'detailPath' => $path]]);
    }

    /** Lightweight local-catalog lookup by subjectId: title, cover, detailPath. */
    public function item(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subjectId' => ['required', 'string', 'max:64'],
        ]);

        $row = CatalogItem::query()->where('subject_id', $validated['subjectId'])->first();

        if ($row === null) {
            return response()->json(['data' => null]);
        }

        $payload = is_array($row->payload) ? $row->payload : [];

        return response()->json(['data' => $payload + [
            'subjectId' => $row->subject_id,
            'subjectType' => $row->subject_type,
            'typeLabel' => SubjectType::resolve($row->subject_type)->label(),
            'title' => $row->title,
            'cover' => $row->cover,
            'year' => $row->year,
            'imdbRating' => $row->imdb_rating,
            'genres' => $row->genres ?? [],
            'detailPath' => $row->detail_path,
        ]]);
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
    /**
     * @return array<int,array<string,mixed>>
     */
    protected function searchItems(string $query, int $subjectType, int $perPage, int $page = 1): array
    {
        try {
            $data = $this->client->search($query, $subjectType, $page, $perPage);

            $items = [];
            foreach (ItemNormalizer::many($data['items'] ?? []) as $item) {
                // Titles containing "anim" must be confirmed as real animation
                // ("XXX: The Animation" hentai never reach discovery pages).
                if (! $this->searchGate($item)) {
                    continue;
                }
                $items[] = $item;
            }

            return $items;
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
    /**
     * One named section of the H5 web home feed (movieboxhd.net), normalized
     * as a flat item list. Accepts a title or a list of localized aliases
     * (the feed title varies with the request locale: "Films Tendance" vs
     * "Trending Movies"). Returns [] when the feed or the section is missing.
     *
     * @param  string|array<int,string>  $sectionTitles
     */
    protected function h5Row(string|array $sectionTitles): array
    {
        try {
            $data = $this->client->h5Home();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $aliases = (array) $sectionTitles;

        foreach (($data['operatingList'] ?? []) as $section) {
            if (! is_array($section) || ! in_array($section['title'] ?? '', $aliases, true)) {
                continue;
            }

            $seen = [];
            $items = [];
            foreach (ItemNormalizer::many($section['subjects'] ?? []) as $item) {
                if (isset($seen[$item['subjectId']])) {
                    continue;
                }
                // Adult/porn keywords are never shown on home rows.
                if (ContentFilter::isBlocked($item)) {
                    continue;
                }
                // Keep French/English/VO/VOSTFR titles (quality tags such as
                // [CAM] tolerated, like the provider's own home); drop titles
                // carrying only foreign dubs (Hindi, Tamil…).
                if (! VersionFilter::acceptsForRow((string) ($item['title'] ?? ''))) {
                    continue;
                }
                $seen[$item['subjectId']] = true;
                $items[] = $item;
            }

            return $items;
        }

        return [];
    }

    /**
     * TMDB keywords/genres that mark a title as adult/hentai/erotica. The
     * bare "adult"/"sex" words are deliberately absent: they false-positive
     * on legit content ("Adult Swim", sex scenes in dramas).
     */
    protected const PORN_MARKERS = [
        'hentai', 'ecchi', 'yaoi', 'yuri', 'bara', 'shotacon', 'lolicon',
        'hardcore', 'softcore', 'porn', 'porno', 'pornographic', 'xxx',
        'x-rated', 'x rated', 'erotica', 'erotic', 'erotique', 'nudity',
        'nude', 'fetish', 'bondage', 'bdsm', 'milf', 'cougar',
        'tentacle', 'tentacles', 'ahegao', 'bukkake', 'orgy', 'orgie',
        'swingers', 'voyeur', 'cuckold', 'sex tape', 'sextape', 'sexshop',
        'adult film', 'adult movie', 'adult video', 'pink film',
    ];

    /**
     * Unambiguous softcore-genre tags (AniList canonical names) that mark a
     * title as porn/hentai regardless of the media-level isAdult flag.
     */
    protected const STRICT_ADULT_TAGS = [
        'hentai', 'ecchi', 'yaoi', 'yuri', 'bara', 'shotacon', 'lolicon',
        'tentacle', 'tentacles', 'ahegao', 'bukkake', 'orgy', 'orgie',
        'hardcore', 'softcore', 'bdsm', 'bondage', 'milf', 'cougar',
        'netorare', 'ntr', 'cuckold', 'futanari',
    ];

    /**
     * Gate for search/discovery results. Titles containing "anim" (mostly
     * "XXX: The Animation" hentai) must be confirmed as real animation via
     * animationGate — anything unresolved is dropped. Other titles keep the
     * lenient metadata guard (unresolved items are kept).
     *
     * @param  array<string,mixed>  $item
     */
    protected function searchGate(array $item): bool
    {
        // Title hard block: "The Animation" hentai OVA pattern always drops,
        // with no metadata round-trip.
        if (ContentFilter::titleHardBlocked((string) ($item['title'] ?? ''))) {
            return false;
        }

        // Items carrying genres are already filtered for free by ContentFilter:
        // the upstream tags adult content with the genre "Adulte" (and the
        // search-sourced pools all carry genres) — nothing to verify here.
        if (! empty($item['genres'])) {
            return true;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/\[[^\]]*\]/u', '', (string) ($item['title'] ?? ''))));
        if ($clean === '') {
            return true;
        }

        // Suspicion-triggered gate for genre-less rows (h5 editorial sections):
        // a clean title passes instantly; only titles carrying an adult or
        // animation marker are verified against metadata. Verdicts are cached
        // for a day.
        if (! $this->riskyTitle($clean)) {
            return true;
        }

        $kind = (int) ($item['subjectType'] ?? 0) === 2 ? 'tv' : 'movie';
        $key = 'guard:v2:suspect:'.$kind.':'.md5(mb_strtolower($clean));

        return Cache::remember($key, 86400, fn () => $this->verifySuspect($item, $clean));
    }

    /**
     * Title markers that justify a metadata check. Wide on purpose: a false
     * trigger only costs one cached lookup (rare), never a wrongful block.
     */
    protected function riskyTitle(string $clean): bool
    {
        return preg_match(
            '/\b(?:anim|hentai|ecchi|erot|sex|xxx|taboo|milf|anal|adulte?|soeur|belle-m[eè]re|18\+|step)\b|the\s+animation/iu',
            $clean
        ) === 1;
    }

    /**
     * Verify a suspect title with a single cheap lookup: AniList for
     * animation-suffixed names (hentai OVAs), iTunes for the rest. Unresolved
     * titles are kept — the blocklist already caught the explicit ones.
     *
     * @param  array<string,mixed>  $item
     */
    protected function verifySuspect(array $item, string $clean): bool
    {
        if (preg_match('/anim/iu', $clean) === 1) {
            $verdict = $this->animeIsAdult($clean);

            return $verdict === null || ! $verdict;
        }

        if ($this->itunesIsAdult($clean, (int) ($item['subjectType'] ?? 0) === 2 ? 'tv' : 'movie')) {
            return false;
        }

        return true;
    }

    /**
     * Animation-category gate: the item must be dropped when adult/hentai
     * AND kept only when the metadata confirms it really is animation
     * (AniList/MAL record, or DioStream genre "Animation"). Anything
     * unresolvable or non-animation is dropped, so search junk never
     * pollutes the category.
     *
     * @param  array<string,mixed>  $item
     */
    protected function animationGate(array $item): bool
    {
        // Title hard block: "The Animation" is the hentai OVA naming pattern —
        // always dropped, no metadata lookup.
        if (ContentFilter::titleHardBlocked((string) ($item['title'] ?? ''))) {
            return false;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/\[[^\]]*\]/u', '', (string) ($item['title'] ?? ''))));
        if ($clean === '') {
            return false;
        }

        $kind = (int) ($item['subjectType'] ?? 0) === 2 ? 'tv' : 'movie';
        $key = 'guard:v1:animcat:'.$kind.':'.md5(mb_strtolower($clean));

        return Cache::remember($key, 86400, fn () => $this->animationGateUncached($item, $clean));
    }

    /**
     * Lookup variants for an anime title: the title as-is, then the title
     * stripped of its trailing "The Animation" / "The Motion Picture"
     * suffix, which is how hentai OVAs are typically named.
     *
     * @return list<string>
     */
    protected function animeTitleVariants(string $clean): array
    {
        $variants = [$clean];
        $short = trim((string) preg_replace(
            '/\s*[:：\-]?\s*(?:the\s+)?(?:animation|motion\s+picture|movie|ova)\s*$/iu',
            '',
            $clean
        ));
        if ($short !== '' && $short !== $clean) {
            $variants[] = $short;
        }

        return $variants;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    protected function animationGateUncached(array $item, string $clean): bool
    {
        // AniList: found = confirmed anime; adult markers drop it. When the
        // full title misses, retry without the "The Animation" suffix —
        // most hentai OVAs are named "XXX: The Animation" and their plain
        // title resolves reliably.
        foreach ($this->animeTitleVariants($clean) as $variant) {
            $media = $this->anilist()->search($variant);
            if ($media !== null) {
                if (($media['isAdult'] ?? false) === true) {
                    return false;
                }
                $genres = array_map('mb_strtolower', (array) ($media['genres'] ?? []));
                if (in_array('hentai', $genres, true)) {
                    return false;
                }
                foreach ((array) ($media['tags'] ?? []) as $tag) {
                    if (is_array($tag)
                        && ($tag['isAdult'] ?? false) === true
                        && in_array(mb_strtolower((string) ($tag['name'] ?? '')), self::STRICT_ADULT_TAGS, true)
                    ) {
                        return false;
                    }
                }

                return true;
            }
        }

        // Jikan/MAL: found = confirmed anime; Hentai genre drops it.
        foreach ($this->animeTitleVariants($clean) as $variant) {
            $jikan = $this->jikan()->search($variant);
            if ($jikan !== null) {
                $genres = array_map('mb_strtolower', (array) ($jikan['genres'] ?? []));
                $rating = mb_strtolower((string) ($jikan['rating'] ?? ''));
                if (in_array('hentai', $genres, true) || str_contains($rating, 'hentai') || str_contains($rating, 'rx')) {
                    return false;
                }

                return true;
            }
        }

        // DioStream/TMDB fallback: adult/porn drops; only the "Animation"
        // genre confirms the title.
        $meta = $this->dioResolve($item);
        if ($meta !== null) {
            if (($meta['adult'] ?? false) === true || $this->metadataIsPorn($meta)) {
                return false;
            }
            if ($this->metadataHasGenre($meta, 'animation')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adult/hentai gate for editorial home rows, resolved against public
     * metadata sources — no API key required:
     *
     *  - anime rows: AniList (isAdult, Hentai/Ecchi genres, adult tags),
     *    cross-checked with Jikan/MAL when AniList misses;
     *  - film/series rows: iTunes (contentAdvisoryRating, genres,
     *    description) when reachable, then DioStream/TMDB;
     *  - DioStream/TMDB is the final fallback for both kinds.
     *
     * Unresolvable items are kept (the title-level gate already ran).
     *
     * @param  array<string,mixed>  $item
     */
    protected function metadataGuard(array $item, bool $animeHint = false): bool
    {
        $clean = trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/\[[^\]]*\]/u', '', (string) ($item['title'] ?? ''))));
        if ($clean === '') {
            return true;
        }

        // The verdict is cached for a day so a snapshot rebuild stays fast
        // even after the upstream metadata caches (300s) have expired.
        $kind = (int) ($item['subjectType'] ?? 0) === 2 ? 'tv' : 'movie';
        $key = 'guard:v1:verdict:'.$kind.':'.($animeHint ? 'anime' : 'film').':'.md5(mb_strtolower($clean));

        return Cache::remember($key, 86400, fn () => $this->metadataGuardUncached($item, $animeHint));
    }

    /**
     * @param  array<string,mixed>  $item
     */
    protected function metadataGuardUncached(array $item, bool $animeHint): bool
    {
        $clean = trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/\[[^\]]*\]/u', '', (string) ($item['title'] ?? ''))));

        if ($animeHint) {
            $verdict = $this->animeIsAdult($clean);
            if ($verdict !== null) {
                return ! $verdict;
            }
        } else {
            if ($this->itunesIsAdult($clean, (int) ($item['subjectType'] ?? 0) === 2 ? 'tv' : 'movie')) {
                return false;
            }
        }

        // Final fallback: DioStream/TMDB metadata (adult flag, genres,
        // keywords) — reused by the legacy home rows as well.
        $meta = $this->dioResolve($item);
        if ($meta === null) {
            return true;
        }
        if (($meta['adult'] ?? false) === true) {
            return false;
        }
        if ($this->metadataIsPorn($meta)) {
            return false;
        }

        return true;
    }

    /**
     * @return bool|null true = adult/hentai, false = clean, null = unresolved
     */
    protected function animeIsAdult(string $clean): ?bool
    {
        $media = $this->anilist()->search($clean);
        if ($media !== null) {
            // "The Animation"/"The Motion Picture" is how hentai OVAs are named
            // — but a handful of legit series carry the same suffix, and AniList
            // flags borderline mainstream titles adult. For suffixed titles only
            // the unambiguous markers (Hentai genre, strict adult tags) block;
            // the bare isAdult flag does not.
            $suffixed = preg_match('/(?:the\s+animation|the\s+motion\s+picture)$/iu', $clean) === 1;

            if (($media['isAdult'] ?? false) === true && ! $suffixed) {
                return true;
            }
            $genres = array_map('mb_strtolower', (array) ($media['genres'] ?? []));
            // Only the "Hentai" genre drops a title — "Ecchi" is too noisy
            // (mainstream shows like Kill la Kill or Food Wars carry it).
            if (in_array('hentai', $genres, true)) {
                return true;
            }
            // Only unambiguous softcore-genre tags drop a title — and only
            // when AniList itself flags the tag as adult. The bare name is
            // too noisy ("Yuri" appears on mainstream shows like Kill la
            // Kill) and the bare isAdult flag too ("Rape" on Sword Art
            // Online); both together isolate real hentai/ecchi markers.
            foreach ((array) ($media['tags'] ?? []) as $tag) {
                if (is_array($tag)
                    && ($tag['isAdult'] ?? false) === true
                    && in_array(mb_strtolower((string) ($tag['name'] ?? '')), self::STRICT_ADULT_TAGS, true)
                ) {
                    return true;
                }
            }

            return false;
        }

        $jikan = $this->jikan()->search($clean);
        if ($jikan !== null) {
            $genres = array_map('mb_strtolower', (array) ($jikan['genres'] ?? []));
            $rating = mb_strtolower((string) ($jikan['rating'] ?? ''));
            if (in_array('hentai', $genres, true) || in_array('ecchi', $genres, true)) {
                return true;
            }
            if (str_contains($rating, 'hentai') || str_contains($rating, 'rx')) {
                return true;
            }

            return false;
        }

        return null;
    }

    /**
     * @return true when iTunes marks the title as explicitly adult. The
     * source is skipped for a while after an HTTP failure (circuit breaker)
     * so a flaky upstream never slows the home build.
     */
    protected function itunesIsAdult(string $clean, string $kind): bool
    {
        if (Cache::get('guard:itunes:down')) {
            return false;
        }

        $meta = $this->itunes()->search($clean, $kind);

        if ($meta === null) {
            if (Cache::get('guard:itunes:fail') !== null) {
                Cache::put('guard:itunes:down', 1, 900);
            } else {
                Cache::put('guard:itunes:fail', 1, 300);
            }

            return false;
        }

        Cache::forget('guard:itunes:fail');

        $rating = mb_strtoupper((string) ($meta['contentAdvisoryRating'] ?? ''));
        if (in_array($rating, ['X', 'XXX', 'NC-17', 'AO'], true)) {
            return true;
        }

        $hay = mb_strtolower(implode(' ', (array) ($meta['genres'] ?? [])).' '.$meta['description']);
        $hay = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $hay) ?: $hay;
        $hay = ' '.preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]/', ' ', $hay)).' ';

        foreach (self::PORN_MARKERS as $marker) {
            if (str_contains($hay, ' '.$marker.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Precise gate for curated home rows. Instead of trusting title tags,
     * each item is resolved against the DioStream (TMDB) catalog and dropped
     * when the real metadata marks it adult / porn / hentai (adult flag,
     * genres, keywords). The row's kind (movie/tv) and genre ("Animation")
     * can additionally be enforced so a row never leaks the wrong content.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    protected function preciseFilter(array $items, ?string $requireGenre = null, ?int $requireType = null): array
    {
        $out = [];
        foreach ($items as $item) {
            $meta = $this->dioResolve($item);

            // Unresolvable items are kept — the title-level gate already ran.
            if ($meta === null) {
                $out[] = $item;
                continue;
            }

            if (($meta['adult'] ?? false) === true) {
                continue;
            }

            if ($this->metadataIsPorn($meta)) {
                continue;
            }

            $kind = strtolower((string) ($meta['mediaType'] ?? ''));
            if ($requireType === 1 && $kind === 'tv') {
                continue;
            }
            if ($requireType === 2 && $kind === 'movie') {
                continue;
            }

            if ($requireGenre !== null && ! $this->metadataHasGenre($meta, $requireGenre)) {
                continue;
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * Resolve a normalized item to its full DioStream/TMDB metadata: title
     * search (tags stripped), best-match pick, then the full movie/tv record.
     * Both the search and the record are cached by DioStreamClient itself.
     *
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    protected function dioResolve(array $item): ?array
    {
        $clean = trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/\[[^\]]*\]/u', '', (string) ($item['title'] ?? ''))));
        if ($clean === '') {
            return null;
        }

        $isTv = (int) ($item['subjectType'] ?? 0) === 2;
        $type = $isTv ? 'tv' : 'movie';

        try {
            $result = $this->dio->search($clean, $type, 'popular');
            $best = $this->pickBestMatch($result['results'] ?? [], $clean, $item);
            if ($best === null || empty($best['tmdb_id'])) {
                return null;
            }

            $meta = $isTv
                ? $this->dio->tv((string) $best['tmdb_id'], false)
                : $this->dio->movie((string) $best['tmdb_id']);

            return is_array($meta) && $meta !== [] ? $meta : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $results
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    protected function pickBestMatch(array $results, string $clean, array $item): ?array
    {
        if ($results === []) {
            return null;
        }

        $normalize = fn ($s) => mb_strtolower((string) preg_replace('/[^\p{L}\p{N} ]/u', '', (string) $s));
        $target = $normalize($clean);
        $itemYear = (int) ($item['year'] ?? 0);

        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($results as $r) {
            if (! is_array($r)) {
                continue;
            }
            $score = levenshtein($target, $normalize($r['title'] ?? ''));
            if (($r['fuzzy'] ?? false) === true) {
                $score += 3;
            }
            $rYear = (int) ($r['year'] ?? 0);
            if ($itemYear > 0 && $rYear > 0 && abs($itemYear - $rYear) > 4) {
                $score += 5;
            }
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }

        return $best;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function metadataIsPorn(array $meta): bool
    {
        $hay = mb_strtolower(
            implode(' ', (array) ($meta['genres'] ?? []))
            .' '
            .implode(' ', (array) ($meta['keywords'] ?? []))
        );
        $hay = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $hay) ?: $hay;
        $hay = ' '.preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]/', ' ', $hay)).' ';

        foreach (self::PORN_MARKERS as $marker) {
            if (str_contains($hay, ' '.$marker.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    protected function metadataHasGenre(array $meta, string $genre): bool
    {
        foreach ((array) ($meta['genres'] ?? []) as $g) {
            if (mb_strtolower((string) $g) === mb_strtolower($genre)) {
                return true;
            }
        }

        return false;
    }

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
    public function buildChannels(): array
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
    /**
     * Return a list of similar titles from the persisted local catalog, based
     * on shared genres. Local-only (no upstream call) — used for the detail
     * page's recommendations.
     *
     * @param  array<string,mixed>  $item
     * @return array<int,array<string,mixed>>
     */
    protected function recommendationsFromLocal(array $item, int $limit): array
    {
        $genres = array_map(fn ($g) => mb_strtolower(trim((string) $g)), (array) ($item['genres'] ?? []));
        if ($genres === []) {
            return [];
        }

        try {
            $rows = CatalogItem::query()
                ->where('subject_id', '!=', (string) ($item['subjectId'] ?? ''))
                ->orderByDesc('seen_count')
                ->limit(120)
                ->get();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $rowGenres = array_map(fn ($g) => mb_strtolower(trim((string) $g)), (array) $row->genres);
            if (count(array_intersect($genres, $rowGenres)) === 0) {
                continue;
            }
            $normalized = ItemNormalizer::one([
                'subjectId' => (string) $row->subject_id,
                'subjectType' => (int) $row->subject_type,
                'title' => $row->title,
                'cover' => $row->cover,
                'description' => $row->description,
                'genres' => $row->genres,
                'imdbRating' => $row->imdb_rating,
                'releaseDate' => $row->release_date,
                'year' => $row->year,
                'durationSeconds' => $row->duration_seconds,
                'country' => $row->country,
                'detailPath' => $row->detail_path,
                'hasResource' => true,
            ]);
            if ($normalized !== null) {
                $out[] = $normalized;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    protected function buildDetail(array $validated, bool $debug = false): array
    {
        $subjectId = $validated['subjectId'];

        // Fetch the subject detail and (for series) the season info in one
        // concurrent round trip — the two upstream calls used to run serially
        // and doubled the detail-page latency.
        $isSeriesHint = (int) ($validated['subjectType'] ?? 0) === SubjectType::TV_SERIES->value;
        $bundle = [];
        try {
            $bundle = $this->client->detailBundle($subjectId, $isSeriesHint);
        } catch (\Throwable $e) {
            report($e);
        }

        $detail = $bundle['item'] ?? null;
        $bundledSeasons = $bundle['seasons'] ?? null;

        $item = is_array($detail) ? ItemNormalizer::one($detail) : null;

        // DioStream fallback: when the MovieBox transport has no detail for the
        // subject, rebuild it from the diostream.cc metadata (TMDB-shaped).
        $dioDetail = $item === null ? $this->dioDetail($validated) : null;
        $item ??= $dioDetail['item'] ?? null;

        $item ??= [
            'subjectId' => $subjectId,
            'subjectType' => (int) ($validated['subjectType'] ?? 0),
            'typeLabel' => SubjectType::resolve($validated['subjectType'] ?? 0)->label(),
            'title' => self::firstNonEmpty($validated['title'] ?? null, 'Untitled'),
            'displayTitle' => TextSanitizer::displayTitle(self::firstNonEmpty($validated['title'] ?? null, 'Untitled')),
            'description' => null,
            'cover' => self::firstNonEmpty($validated['cover'] ?? null),
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
            if ($dioDetail !== null) {
                $seasons = $dioDetail['seasons'];
            } elseif (is_array($bundledSeasons)) {
                $seasons = $this->normalizeSeasons($bundledSeasons['seasons'] ?? []);
            } else {
                try {
                    $seasonData = $this->client->seasonInfo($subjectId);
                    $seasons = $this->normalizeSeasons($seasonData['seasons'] ?? []);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $cast = is_array($detail)
            ? $this->normalizeCast($detail['staffList'] ?? [])
            : ($dioDetail['cast'] ?? []);
        $dubs = $this->ensureFrenchVersion(
            is_array($detail) ? $this->normalizeDubs($detail['dubs'] ?? []) : [],
            $item
        );
        // Version / Langue selector: only French / English / VOSTFR / VO dubs.
        $dubs = array_values(array_filter($dubs, fn ($dub) => VersionFilter::accepts(
            '['.trim((string) ($dub['label'] ?? '')).']'
        )));

        $recommendations = [];
        if (! empty($item['genres'][0])) {
            // Local-first: recommend from the persisted catalog (instant, no
            // upstream call). Falls back to a live search when the catalog is
            // empty so recommendations never silently disappear.
            $local = $this->recommendationsFromLocal($item, 12);
            $recommendations = $local !== [] ? $local : array_values(array_filter(
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
            'detailAvailable' => is_array($detail) || $dioDetail !== null,
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
     * DioStream fallback for a detail request: resolves the TMDB id (numeric
     * subject id, else a title search) and rebuilds the item, seasons and cast
     * from the DioStream metadata.
     *
     * @param  array<string,mixed>  $validated
     * @return array{item:array<string,mixed>,seasons:array<int,mixed>,cast:array<int,mixed>}|null
     */
    protected function dioDetail(array $validated): ?array
    {
        try {
            $subjectId = (string) ($validated['subjectId'] ?? '');
            $tmdb = ctype_digit($subjectId) ? $subjectId : null;

            if ($tmdb === null && ! empty($validated['title'])) {
                $results = $this->dio->search((string) $validated['title'])['results'] ?? [];
                foreach ($results as $r) {
                    if (is_array($r) && ! empty($r['tmdb_id'])) {
                        $tmdb = (string) $r['tmdb_id'];
                        break;
                    }
                }
            }
            if ($tmdb === null) {
                return null;
            }

            $kind = (int) ($validated['subjectType'] ?? 0) === SubjectType::TV_SERIES->value ? 'tv' : 'auto';
            $item = $this->dio->itemDetail($tmdb, $kind);

            $seasons = [];
            $cast = [];
            if ($item['subjectType'] === SubjectType::TV_SERIES->value) {
                $seasons = $this->dio->seasons($tmdb);
                $meta = $this->dio->tv($tmdb, false);
                $cast = array_values(array_filter(array_map(
                    fn ($c) => is_array($c) ? [
                        'name' => $c['name'] ?? null,
                        'character' => $c['character'] ?? null,
                        'avatar' => $c['profile'] ?? null,
                    ] : null,
                    $meta['cast'] ?? []
                )));
            }

            return ['item' => $item, 'seasons' => $seasons, 'cast' => $cast];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
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
