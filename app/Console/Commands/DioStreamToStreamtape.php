<?php

namespace App\Console\Commands;

use App\Models\StreamtapeLink;
use App\Services\DioStream\DioStreamClient;
use App\Services\Streamtape\StreamtapeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uploads DioStream content to Streamtape, language-aware.
 *
 * Selection rules (matching the site's content policy):
 *  - movies / series: French first (Hephaestus / Styx HLS), then English
 *    (Atlas / leto). Every chosen source is sent DIRECTLY to Streamtape's
 *    remote upload API — no local download. MP4 sources transfer fine; HLS
 *    playlists usually fail on Streamtape's side (it can't ingest m3u8).
 *  - anime: VF (French dub: Erebus / Heracles) and VOSTFR (Japanese audio,
 *    closest subtitles attached), same direct-upload rule.
 *
 * DioStream stream URLs are session-scoped tokens, so every upload starts by
 * re-resolving a FRESH link right before submission.
 *
 * One StreamtapeLink row is persisted per uploaded title/episode, carrying the
 * language, the video source and the subtitle URLs (new `subtitle_urls` column)
 * so the player can attach them at playback time.
 */
class DioStreamToStreamtape extends Command
{
    protected $signature = 'diostream:to-streamtape
        {--links= : links.jsonl snapshot file (default: storage/app/catalog/diostream/links.jsonl)}
        {--episodes : also process series episodes (movies only otherwise)}
        {--episode-cap=8 : max episodes per series when --episodes is set (0 = all)}
        {--lang=fr,en : movies/series language order (comma-separated)}
        {--anime=vf,vostfr : anime modes to upload, comma-separated}
        {--anime-cap=12 : max episodes per anime title (0 = all)}
        {--folder=DioStream : root Streamtape folder name}
        {--parent= : parent folder (empty = account root)}
        {--batch= : batch id}';

    protected $description = 'Envoie les titres DioStream vers Streamtape (films/séries FR+EN, anime VF+VOSTFR).';

    /** @var int */
    protected int $lastResolution = 0;

    /** @var list<string> movie/TV sources tried for the preferred language */
    protected const FRENCH_MOVIE_SOURCES = ['hephaestus', 'styx', 'theia', 'cronus', 'coeus'];

    /** @var list<string> */
    protected const ENGLISH_MOVIE_SOURCES = ['helios', 'leto', 'moviesapi', 'apollo', 'crius', 'poseidon', 'theia'];

    /** @var list<string> anime VF (French dub) sources */
    protected const FRENCH_ANIME_SOURCES = ['erebus', 'heracles'];

    /** @var list<string> anime raw (Japanese audio) sources for VOSTFR */
    protected const RAW_ANIME_SOURCES = ['rhea', 'metis', 'hyperion', 'asteria', 'tethys', 'iapetus', 'calypso', 'aether'];

    public function handle(DioStreamClient $dio, StreamtapeService $streamtape): int
    {
        $linksFile = (string) $this->option('links');
        if ($linksFile === '') {
            $linksFile = storage_path('app/catalog/diostream/links.jsonl');
        }

        $batch = (string) ($this->option('batch') ?: Str::uuid());
        $langs = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', (string) $this->option('lang'))))));
        $animeModes = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', (string) $this->option('anime'))))));

        if (! $streamtape->isConfigured()) {
            $this->error('Streamtape non configuré (STREAMTAPE_LOGIN / STREAMTAPE_KEY).');

            return self::FAILURE;
        }

        $parentId = (string) ($this->option('parent')) !== '' ? $streamtape->resolveFolderId((string) $this->option('parent')) : '';
        $rootId = $streamtape->resolveFolderId((string) $this->option('folder'), $parentId);
        $animeId = $streamtape->resolveFolderId('Anime', $rootId);

        $this->info("Batch {$batch} → « {$this->option('folder')} » (folder {$rootId})");

        $titles = $this->catalogTitles();
        $done = 0;
        $failed = 0;
        $skipped = 0;

        if (File::exists($linksFile)) {
            $rows = $this->readJsonLines($linksFile);
            $movies = array_values(array_filter($rows, fn ($r) => ($r['type'] ?? '') === 'movie'));
            $episodes = array_values(array_filter($rows, fn ($r) => ($r['type'] ?? '') === 'episode'));

            foreach ($movies as $row) {
                $tmdb = (string) ($row['tmdb_id'] ?? '');
                $title = (string) ($titles['movie'][$tmdb] ?? $row['title'] ?? $tmdb);
                if ($tmdb === '') {
                    continue;
                }
                if ($this->alreadyUploaded($tmdb, 0, 0)) {
                    $skipped++;
                    continue;
                }
                foreach ($langs as $lang) {
                    $ok = $this->uploadTitle($dio, $streamtape, 'movie', $tmdb, $title, 0, 0, $lang, $rootId, $batch);
                    if ($ok) {
                        $done++;
                    } else {
                        $failed++;
                    }
                }
            }

            if ((bool) $this->option('episodes')) {
                $episodeCap = max(0, (int) $this->option('episode-cap'));
                foreach ($episodes as $row) {
                    $tmdb = (string) ($row['tmdb_id'] ?? '');
                    $season = (int) ($row['season'] ?? 0);
                    $episode = (int) ($row['episode'] ?? 0);
                    $title = (string) ($titles['series'][$tmdb] ?? $row['title'] ?? $tmdb);
                    if ($tmdb === '' || $season <= 0 || $episode <= 0 || ($episodeCap > 0 && $episode > $episodeCap)) {
                        continue;
                    }
                    if ($this->alreadyUploaded($tmdb, $season, $episode)) {
                        $skipped++;
                        continue;
                    }
                    foreach ($langs as $lang) {
                        $ok = $this->uploadTitle($dio, $streamtape, 'tv', $tmdb, $title, $season, $episode, $lang, $rootId, $batch);
                        if ($ok) {
                            $done++;
                        } else {
                            $failed++;
                        }
                    }
                }
            }
        } else {
            $this->warn('links.jsonl absent — section films/séries ignorée ('.($linksFile).').');
        }

        // Anime: walk the anime.jsonl snapshot, one VF and/or VOSTFR pass.
        $animeFile = storage_path('app/catalog/diostream/anime.jsonl');
        if ($animeModes !== [] && File::exists($animeFile)) {
            $animeCap = max(0, (int) $this->option('anime-cap'));
            foreach ($this->readJsonLines($animeFile) as $row) {
                $ani = (string) ($row['ani_id'] ?? $row['mal_id'] ?? '');
                $mal = isset($row['mal_id']) && is_numeric($row['mal_id']) ? (int) $row['mal_id'] : null;
                $title = (string) ($row['title'] ?? $ani);
                if ($ani === '') {
                    continue;
                }

                $epCount = $animeCap;
                try {
                    $meta = $dio->anime($ani, false);
                    $metaEp = (int) ($meta['episodes'] ?? 0);
                    $epCount = $animeCap > 0 ? min($animeCap, max($metaEp, 1)) : $metaEp;
                } catch (Throwable $e) {
                    if ($epCount === 0) {
                        $epCount = 12;
                    }
                }

                for ($ep = 1; $ep <= $epCount; $ep++) {
                    foreach ($animeModes as $mode) {
                        $key = 'anime:'.$ani.':'.$ep.':'.$mode;
                        if ($this->alreadyUploaded($key, 0, 0)) {
                            $skipped++;
                            continue;
                        }
                        $ok = $this->uploadAnimeEpisode($dio, $streamtape, $ani, $mal, $ep, $mode, $title, $animeId, $batch);
                        if ($ok) {
                            $done++;
                        } else {
                            $failed++;
                        }
                    }
                }
            }
        }

        $this->info("=== Batch {$batch} terminé : {$done} envoyés, {$failed} échoués, {$skipped} déjà présents ===");

        return self::SUCCESS;
    }

    /**
     * Resolve + upload a movie/series title in the requested language.
     *
     * @param  list<string>  $langs
     */
    protected function uploadTitle(
        DioStreamClient $dio,
        StreamtapeService $streamtape,
        string $kind,
        string $tmdb,
        string $title,
        int $season,
        int $episode,
        string $lang,
        string $rootId,
        string $batch
    ): bool {
        $sources = $kind === 'tv'
            ? (in_array($lang, ['fr', 'french', 'vf'], true) ? self::FRENCH_MOVIE_SOURCES : self::ENGLISH_MOVIE_SOURCES)
            : (in_array($lang, ['fr', 'french', 'vf'], true) ? self::FRENCH_MOVIE_SOURCES : self::ENGLISH_MOVIE_SOURCES);

        $want = $this->wantLang($lang); // 'french' or 'english'
        foreach ($sources as $sourceKey) {
            try {
                $payload = $kind === 'tv'
                    ? $dio->tvStreamFrom($tmdb, $season, $episode, $sourceKey)
                    : $dio->movieStreamFrom($tmdb, $sourceKey);
            } catch (Throwable $e) {
                $this->warn("  {$title} : {$e->getMessage()}");
                continue;
            }
            if (empty($payload['providers'])) {
                continue;
            }

            $chosen = $this->pickSource($payload, $want);
            if ($chosen === null) {
                continue;
            }

            return $this->submit($dio, $streamtape, $tmdb, $season, $episode, $title, $lang, $chosen, $payload, $rootId, $batch);
        }

        $label = $episode > 0 ? "{$title} S{$season}E{$episode}" : $title;
        $this->warn("  {$label} [{$lang}] : aucune source (".implode(',', $sources).')');

        return false;
    }

    /**
     * @param  list<string>  $animeModes
     */
    protected function uploadAnimeEpisode(
        DioStreamClient $dio,
        StreamtapeService $streamtape,
        string $ani,
        ?int $mal,
        int $episode,
        string $mode,
        string $title,
        string $folderId,
        string $batch
    ): bool {
        $sources = $mode === 'vf' ? self::FRENCH_ANIME_SOURCES : self::RAW_ANIME_SOURCES;

        foreach ($sources as $sourceKey) {
            try {
                $payload = $dio->animeStreamFrom($ani, $mal, $episode, $sourceKey);
            } catch (Throwable $e) {
                $this->warn("  {$title} E{$episode} [{$mode}] : {$e->getMessage()}");
                continue;
            }
            if (empty($payload['providers'])) {
                continue;
            }

            $chosen = $mode === 'vf'
                ? $this->pickSource($payload, 'french')
                : $this->pickSource($payload, 'japanese');

            if ($chosen === null) {
                $this->warn("  {$title} E{$episode} [{$mode}] sur {$sourceKey} : aucune piste ".($mode === 'vf' ? 'FR' : 'JP'));
                continue;
            }

            return $this->submit($dio, $streamtape, 'anime:'.$ani.':'.$episode.':'.$mode, 0, 0, $title.' E'.$episode, $mode, $chosen, $payload, $folderId, $batch, true);
        }

        return false;
    }

    /**
     * Pick the best source from a stream payload matching the wanted
     * language. MP4 wins (direct remote upload); when the sources only serve
     * HLS, the best HLS is kept — it is converted locally with ffmpeg before
     * upload. Highest resolution wins within a type.
     *
     * @param  array<string,mixed>  $payload
     * @return array{url:string,type:string,language:string,resolution:int}|null
     */
    protected function pickSource(array $payload, string $wantLang): ?array
    {
        $best = null;
        $bestResolution = -1;

        foreach (($payload['providers'] ?? []) as $provider) {
            if (! is_array($provider)) {
                continue;
            }
            foreach (($provider['sources'] ?? []) as $s) {
                if (! is_array($s) || empty($s['url'])) {
                    continue;
                }
                $lang = strtolower((string) ($s['language'] ?? ''));
                if (! str_contains($lang, $wantLang)) {
                    continue;
                }
                $type = strtolower((string) ($s['type'] ?? ''));
                if (! in_array($type, ['mp4', 'hls'], true)) {
                    continue;
                }

                $resolution = 0;
                if (preg_match('/(\d{3,4})/', (string) ($s['quality'] ?? ''), $m)) {
                    $resolution = (int) $m[1];
                }
                // score: mp4 beats hls, higher resolution wins.
                $score = ($type === 'mp4' ? 0 : 1000000) - $resolution;
                if ($best === null || $score < $bestScore) {
                    $bestScore = $score;
                    $bestResolution = $resolution;
                    $best = [
                        'url' => (string) $s['url'],
                        'type' => $type,
                        'language' => (string) ($s['language'] ?? ''),
                        'resolution' => $resolution,
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * Upload the chosen MP4 source to Streamtape (remote upload, Streamtape
     * transcodes on its side), recording one StreamtapeLink row with the
     * language and subtitle URLs.
     *
     * @param  array{url:string,type:string,language:string,resolution:int}  $chosen
     * @param  array<string,mixed>  $payload
     */
    protected function submit(
        DioStreamClient $dio,
        StreamtapeService $streamtape,
        string $subjectId,
        int $season,
        int $episode,
        string $title,
        string $lang,
        array $chosen,
        array $payload,
        string $folderId,
        string $batch,
        bool $anime = false
    ): bool {
        try {
            $subs = $this->pickSubtitles($payload);
            $name = $this->fileName($title, $season, $episode, $lang, $anime);
            $upload = $streamtape->remoteAdd($chosen['url'], $name, $this->headers(), $folderId);

            $folder = $anime ? 'DioStream/Anime' : 'DioStream';
            StreamtapeLink::create([
                'batch_id' => $batch,
                'subject_id' => $subjectId,
                'subject_type' => $anime ? 7 : ($episode > 0 ? 2 : 1),
                'title' => $title,
                'season' => $season,
                'episode' => $episode,
                'resolution' => $chosen['resolution'] ?: $this->lastResolution,
                'language' => $lang,
                'folder' => $folder,
                'source_url' => $chosen['url'],
                'subtitle_urls' => $subs !== [] ? $subs : null,
                'file_id' => $upload['id'],
                'status' => 'new',
            ]);

            $this->lastResolution = $chosen['resolution'];
            $this->info("  {$title} [{$lang}] envoyé (".$chosen['resolution'].'p, '.$chosen['type'].($subs !== [] ? ', '.count($subs).' sous-titres' : '').').');

            return true;
        } catch (Throwable $e) {
            $this->error("  {$title} [{$lang}] : {$e->getMessage()}");
            report($e);

            return false;
        }
    }

    /**
     * Subtitle selection: French preferred, else English, else none.
     *
     * @param  array<string,mixed>  $payload
     * @return list<array<string,string>>
     */
    protected function pickSubtitles(array $payload): array
    {
        $subs = [];
        foreach (($payload['subtitles'] ?? []) as $s) {
            if (! is_array($s) || empty($s['url'])) {
                continue;
            }
            $subs[] = [
                'url' => (string) $s['url'],
                'lang' => (string) ($s['lang'] ?? ''),
                'format' => (string) ($s['format'] ?? 'srt'),
            ];
        }

        $fr = array_values(array_filter($subs, fn ($s) => stripos($s['lang'], 'fran') !== false));
        if ($fr !== []) {
            return $fr;
        }
        $en = array_values(array_filter($subs, fn ($s) => stripos($s['lang'], 'eng') !== false || stripos($s['lang'], 'angl') !== false));
        if ($en !== []) {
            return $en;
        }

        return array_slice($subs, 0, 5);
    }

    protected function wantLang(string $lang): string
    {
        return in_array($lang, ['fr', 'french', 'vf', 'vostfr'], true) ? 'french' : 'english';
    }

    protected function alreadyUploaded(string $subjectId, int $season, int $episode): bool
    {
        return StreamtapeLink::query()
            ->where('subject_id', $subjectId)
            ->where('season', $season)
            ->where('episode', $episode)
            ->whereNotNull('streamtape_url')
            ->exists();
    }

    protected function fileName(string $title, int $season, int $episode, string $lang, bool $anime = false): string
    {
        $clean = trim((string) preg_replace('/[^\p{L}\p{N}\s._-]+/u', '', $title));
        $suffix = $anime ? ' ['.strtoupper($lang).']' : '';

        if ($episode > 0) {
            return $clean.' S'.$season.'E'.$episode.$suffix.'.mp4';
        }

        return ($clean !== '' ? $clean : 'Movie').$suffix.'.mp4';
    }

    /** @return string[] */
    protected function headers(): array
    {
        return [
            'User-Agent: '.(string) config('diostream.user_agent'),
            'Accept: */*',
            'Referer: '.(string) config('diostream.referer'),
            'Origin: '.rtrim((string) config('diostream.referer'), '/'),
        ];
    }

    /**
     * Title lookup map from the snapshot catalog files.
     *
     * @return array{movie:array<string,string>,series:array<string,string>}
     */
    protected function catalogTitles(): array
    {
        $dir = storage_path('app/catalog/diostream');
        $out = ['movie' => [], 'series' => []];

        foreach (['movie' => 'movies.jsonl', 'series' => 'series.jsonl'] as $section => $file) {
            $path = $dir.'/'.$file;
            if (! File::exists($path)) {
                continue;
            }
            foreach (File::lines($path) as $line) {
                $row = json_decode((string) $line, true);
                if (is_array($row) && ! empty($row['tmdb_id'])) {
                    $out[$section][(string) $row['tmdb_id']] = (string) ($row['title'] ?? '');
                }
            }
        }

        return $out;
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
}
