<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogRepository;
use App\Services\DioStream\DioStreamClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Snapshots the whole DioStream catalog (movies + anime browses, series
 * discovered through letter searches) and, optionally, the stream links of
 * every title, into storage/app/catalog/diostream/.
 *
 * Stream links are session-scoped CDN tokens: they expire, so each link record
 * stores the exact source key + captured payload and a `fetchedAt` timestamp.
 * Playback always re-resolves links live (DioStreamClient::movieStream /
 * tvStream); this snapshot is a searchable local copy of the catalog.
 *
 * The walk is resumable: progress is tracked in state.json, every page is
 * appended immediately, and re-running the command continues where it stopped.
 */
class DioStreamSnapshot extends Command
{
    protected $signature = 'diostream:snapshot
        {--types=movies,anime,series : sections to walk (comma-separated)}
        {--pages=0 : max pages per browse section (0 = until empty)}
        {--series-max=500 : max series titles fetched for full episode structure (0 = all)}
        {--streams : also resolve and store stream links}
        {--stream-cap=8 : max episodes per series title when resolving links (0 = all)}
        {--resume : continue from the previous state instead of overwriting}';

    protected $description = 'Snapshot complet du catalogue DioStream (films, anime, séries + liens vidéo optionnels).';

    protected string $dir;

    public function __construct(
        protected DioStreamClient $client,
        protected CatalogRepository $repo,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->dir = storage_path('app/catalog/diostream');
        File::ensureDirectoryExists($this->dir);

        $resume = (bool) $this->option('resume');
        $state = $resume ? $this->readState() : [];
        $types = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('types')))));

        if (in_array('movies', $types, true)) {
            $this->walkBrowse('movies', $state);
        }
        if (in_array('anime', $types, true)) {
            $this->walkBrowse('anime', $state);
        }
        if (in_array('series', $types, true)) {
            $this->walkSeries($state);
        }

        if ((bool) $this->option('streams')) {
            $this->resolveLinks($state, (int) $this->option('stream-cap'));
        }

        $this->writeState($state);
        $this->info('Snapshot terminé. Fichiers dans '.$this->dir);

        return self::SUCCESS;
    }

    // Browse sections (movies / anime) ---------------------------------

    protected function walkBrowse(string $category, array &$state): void
    {
        $maxPages = max(0, (int) $this->option('pages'));
        $resumeFrom = (int) ($state['browse'][$category]['page'] ?? 0);

        $seen = $this->readIds($category);
        $count = count($seen);
        $page = $resumeFrom;

        $this->info("Parcours browse/{$category} (déjà {$count} titres, page {$page})…");

        $items = [];
        while (true) {
            $page++;
            if ($maxPages > 0 && $page > $maxPages) {
                break;
            }

            try {
                $data = $this->client->browse($category, $page);
            } catch (Throwable $e) {
                report($e);
                $this->warn("  page {$page} indisponible ({$e->getMessage()}) — reprise possible avec --resume.");
                break;
            }

            $results = $data['results'] ?? [];
            $added = 0;
            foreach ($results as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = $category === 'anime'
                    ? (string) ($row['ani_id'] ?? $row['mal_id'] ?? '')
                    : (string) ($row['tmdb_id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $row['_id'] = $id;
                $items[] = $row;
                File::append($this->dir.'/'.$category.'.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                $added++;
            }
            $count += $added;
            $state['browse'][$category] = ['page' => $page, 'count' => $count];

            $this->line(sprintf('  %-8s page %-5d +%d (total %d)', $category, $page, $added, $count));

            if (count($results) === 0 || ($added === 0 && count($results) < 20)) {
                break; // last page reached
            }
        }

        if ($items !== []) {
            $this->writeJson($category.'.meta', [
                'type' => $category,
                'count' => $count,
                'updatedAt' => now()->toIso8601String(),
            ]);
        }
    }

    // Series ------------------------------------------------------------

    /**
     * Words used to discover series through the type=tv search (single letters
     * return nothing upstream; searches need 2+ characters).
     *
     * @var list<string>
     */
    protected const SERIES_WORDS = [
        'the', 'love', 'life', 'man', 'woman', 'day', 'world', 'time', 'house',
        'war', 'dead', 'family', 'king', 'queen', 'city', 'star', 'fire',
        'dark', 'night', 'black', 'white', 'high', 'bad', 'true', 'game',
        'last', 'lost', 'great', 'little', 'best', 'secret', 'story', 'walk',
        'fly', 'run', 'fight', 'blood', 'ghost', 'angel', 'devil', 'crime',
        'doctor', 'police', 'school', 'magic', 'space', 'future', 'past',
    ];

    protected function walkSeries(array &$state): void
    {
        $max = max(0, (int) $this->option('series-max'));
        $seen = $this->readIds('series');
        $idsFile = $this->dir.'/series.ids.jsonl';
        foreach ($this->readJsonLines($idsFile) as $entry) {
            $id = (string) ($entry['tmdb_id'] ?? '');
            if ($id !== '') {
                $seen[$id] = true;
            }
        }

        // 1) Discover series ids: the latest-episodes feed (real enumeration
        // surface) plus type=tv searches on common words.
        $this->info('Découverte des séries (feed latest + recherches)…');
        $discovered = 0;
        $addId = function (string $id, ?string $title) use (&$seen, &$discovered, $idsFile): void {
            if ($id === '' || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            File::append($idsFile, json_encode(['tmdb_id' => $id, 'title' => $title], JSON_UNESCAPED_UNICODE)."\n");
            $discovered++;
        };

        $lastPage = (int) ($state['series']['latestPage'] ?? 0);
        $page = $lastPage;
        while (true) {
            $page++;
            try {
                $data = $this->client->latestEpisodes($page);
            } catch (Throwable $e) {
                report($e);
                $this->warn("  latest page {$page} échouée — reprise avec --resume.");
                break;
            }
            $rows = $data['results'] ?? [];
            $count = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $count++;
                $addId((string) ($row['tmdb_id'] ?? ''), $row['title'] ?? null);
            }
            $state['series']['latestPage'] = $page;
            $this->line(sprintf('  latest page %-5d +%d (total %d)', $page, $discovered, count($seen)));
            if ($rows === [] || $count < (int) ($data['page_size'] ?? 1000)) {
                break; // last page
            }
        }

        $startWord = (string) ($state['series']['word'] ?? '');
        foreach (self::SERIES_WORDS as $word) {
            if ($startWord !== '' && $word < $startWord) {
                continue;
            }
            try {
                $data = $this->client->search($word, 'tv');
            } catch (Throwable $e) {
                report($e);
                $this->warn("  recherche '$word' échouée — reprise avec --resume.");
                break;
            }
            foreach (($data['results'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $addId((string) ($row['tmdb_id'] ?? ''), $row['title'] ?? null);
            }
            $state['series']['word'] = $word;
            $this->line(sprintf('  mot %-12s +%d (total %d)', $word, $discovered, count($seen)));
        }

        // 2) Fetch the full structure (seasons/episodes) of each discovered
        // series. This is the expensive part — every title costs one metadata
        // call. Stops at --series-max.
        $this->info("Structure des séries ({$discovered} découvertes)…");
        $entries = $this->readJsonLines($idsFile);
        $fetched = 0;
        $handled = 0;
        foreach ($entries as $entry) {
            $id = $entry['tmdb_id'] ?? null;
            if ($id === null) {
                continue;
            }
            $handled++;
            if (isset($state['series']['done'][$id])) {
                continue;
            }
            if ($max > 0 && $fetched >= $max) {
                $this->warn("  limite --series-max={$max} atteinte.");
                break;
            }

            try {
                $data = $this->client->tv((string) $id, true);
            } catch (Throwable $e) {
                $state['series']['done'][$id] = 'err';
                $state['series']['handled'] = $handled;
                $this->writeState($state);
                continue;
            }

            $seasons = [];
            foreach (($data['seasons'] ?? []) as $season) {
                if (! is_array($season)) {
                    continue;
                }
                $seasons[] = [
                    'season' => (int) ($season['season_number'] ?? 0),
                    'name' => $season['name'] ?? null,
                    'episodeCount' => (int) ($season['episode_count'] ?? 0),
                    'episodes' => array_values(array_filter(array_map(
                        fn ($e) => is_array($e) ? [
                            'id' => $e['id'] ?? null,
                            'number' => $e['episode_number'] ?? null,
                            'name' => $e['name'] ?? null,
                            'airDate' => $e['air_date'] ?? null,
                            'runtime' => $e['runtime'] ?? null,
                        ] : null,
                        $season['episodes'] ?? []
                    ))),
                ];
            }

            $row = [
                'tmdb_id' => (int) $id,
                'title' => $data['title'] ?? $entry['title'] ?? null,
                'year' => $data['year'] ?? null,
                'poster' => $data['poster'] ?? null,
                'backdrop' => $data['backdrop'] ?? null,
                'overview' => $data['overview'] ?? null,
                'genres' => array_values(array_filter((array) ($data['genres'] ?? []), 'is_string')),
                'rating' => $data['rating']['average'] ?? null,
                'status' => $data['status'] ?? null,
                'numberOfSeasons' => count($seasons),
                'seasons' => $seasons,
            ];
            File::append($this->dir.'/series.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

            $fetched++;
            $state['series']['done'][$id] = true;
            $state['series']['handled'] = $handled;
            if ($fetched % 25 === 0) {
                $this->writeState($state);
                $this->line(sprintf('  séries: %d/%d', $fetched, min($max ?: PHP_INT_MAX, count($entries))));
            }
        }

        $this->line(sprintf('  %d séries structurées.', $fetched));
    }

    // Stream links ------------------------------------------------------

    protected function resolveLinks(array &$state, int $episodeCap): void
    {
        $this->info('Résolution des liens vidéo…');
        $movies = $this->readJsonLines($this->dir.'/movies.jsonl');
        $series = $this->readJsonLines($this->dir.'/series.jsonl');

        $movieCount = count($movies);
        $i = (int) ($state['links']['movies']['index'] ?? 0);
        for (; $i < $movieCount; $i++) {
            $id = (string) ($movies[$i]['_id'] ?? $movies[$i]['tmdb_id'] ?? '');
            if ($id === '') {
                continue;
            }
            try {
                $payload = $this->client->movieStream($id);
            } catch (Throwable $e) {
                $this->warn("  movie {$id} : {$e->getMessage()}");
                $state['links']['movies']['index'] = $i + 1;
                $this->writeState($state);
                continue;
            }
            $this->appendLink('movie', $id, null, null, $payload);
            $state['links']['movies']['index'] = $i + 1;
            if ($i % 10 === 0) {
                $this->writeState($state);
                $this->line(sprintf('  films: %d/%d', $i + 1, $movieCount));
            }
        }

        $seriesCount = count($series);
        $j = (int) ($state['links']['series']['index'] ?? 0);
        for (; $j < $seriesCount; $j++) {
            $entry = $series[$j];
            $id = (string) ($entry['tmdb_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $doneEpisodes = 0;
            foreach (($entry['seasons'] ?? []) as $season) {
                $sn = (int) ($season['season'] ?? 0);
                foreach (($season['episodes'] ?? []) as $ep) {
                    if ($episodeCap > 0 && $doneEpisodes >= $episodeCap) {
                        break 2;
                    }
                    $en = (int) ($ep['number'] ?? 0);
                    if ($en <= 0) {
                        continue;
                    }
                    try {
                        $payload = $this->client->tvStream($id, $sn, $en);
                    } catch (Throwable $e) {
                        $this->warn("  {$id} S{$sn}E{$en} : {$e->getMessage()}");
                        continue;
                    }
                    $this->appendLink('episode', $id, $sn, $en, $payload);
                    $doneEpisodes++;
                }
            }
            $state['links']['series']['index'] = $j + 1;
            if ($j % 5 === 0) {
                $this->writeState($state);
                $this->line(sprintf('  séries: %d/%d', $j + 1, $seriesCount));
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function appendLink(string $type, string $tmdb, ?int $season, ?int $episode, array $payload): void
    {
        $link = [
            'type' => $type,
            'tmdb_id' => (int) $tmdb,
            'season' => $season,
            'episode' => $episode,
            'source' => $payload['source'] ?? null,
            'fetchedAt' => now()->toIso8601String(),
            'providers' => $payload['providers'] ?? [],
            'subtitles' => $payload['subtitles'] ?? [],
        ];
        File::append($this->dir.'/links.jsonl', json_encode($link, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    // State / IO --------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    protected function readState(): array
    {
        $file = $this->dir.'/state.json';
        if (! File::exists($file)) {
            return [];
        }

        return json_decode((string) File::get($file), true) ?? [];
    }

    /**
     * @param  array<string,mixed>  $state
     */
    protected function writeState(array $state): void
    {
        File::put($this->dir.'/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Ids already stored for a section, as a set.
     *
     * @return array<string,bool>
     */
    protected function readIds(string $section): array
    {
        $ids = [];
        $file = $this->dir.'/'.$section.'.jsonl';
        if (! File::exists($file)) {
            return $ids;
        }
        foreach (File::lines($file) as $line) {
            $row = json_decode((string) $line, true);
            if (! is_array($row)) {
                continue;
            }
            $id = (string) ($row['_id'] ?? $row['tmdb_id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * @return list<array<string,mixed>>
     */
    protected function readJsonLines(string $file): array
    {
        if (! File::exists($file)) {
            return [];
        }
        $rows = [];
        foreach (File::lines($file) as $line) {
            $row = json_decode((string) $line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    protected function writeJson(string $name, array $data): void
    {
        try {
            File::put(
                $this->dir.'/'.$name.'.json',
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } catch (Throwable $e) {
            report($e);
            $this->warn("  Impossible d'écrire {$name} : {$e->getMessage()}");
        }
    }
}
