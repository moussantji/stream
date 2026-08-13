<?php

namespace App\Services\DioStream;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Native PHP client for the DioStream (diostream.cc) streaming API.
 *
 * DioStream is the backend powering the diostream.cc Netflix-clone SPA. Its
 * catalog endpoints (search / metadata / latest) return every JSON payload
 * encrypted with AES-256-GCM (base64 of [12-byte IV || ciphertext || 16-byte
 * tag]) under a shared key; its stream endpoints return plaintext JSON.
 *
 * This client mirrors the bundle's `window.API` surface: `search`, `metadata`
 * (movie/tv), `latestEpisodes` and `fetchStream` (movie/tv), with the source
 * table statically embedded (it ships inside the SPA bundle).
 */
class DioStreamClient
{
    protected string $base;

    protected string $aesKey;

    protected int $timeout;

    protected int $cacheTtl;

    protected int $paceMs;

    protected int $rateRetries;

    protected string $userAgent;

    protected string $referer;

    protected string $defaultSource;

    protected string $fallbackSource;

    protected static ?float $lastRequestAt = null;

    /**
     * The bundled source table (43 backends, captured from the SPA bundle).
     * Each entry: key, label, path (URL segment), kinds, langs, fast, hidden.
     *
     * @var list<array<string,mixed>>
     */
    protected const SOURCES = [
        ['key' => 'theia', 'label' => 'Theia', 'path' => 'Theia', 'kinds' => ['movie', 'tv'], 'langs' => ['English', 'Spanish', 'Portuguese', 'French', 'German', 'Italian', 'Hindi']],
        ['key' => 'leto', 'label' => 'Leto', 'path' => 'leto', 'kinds' => ['movie', 'tv'], 'langs' => ['English', 'Hindi', 'Tamil', 'Telugu', 'Bengali'], 'fast' => true],
        ['key' => 'iris', 'label' => 'Iris', 'path' => 'Iris', 'kinds' => ['movie', 'tv'], 'langs' => ['Italian', 'English'], 'fast' => true],
        ['key' => 'apollo', 'label' => 'Apollo', 'path' => 'Apollo', 'kinds' => ['movie', 'tv'], 'langs' => ['English']],
        ['key' => 'helios', 'label' => 'Helios', 'path' => 'atlas', 'kinds' => ['movie', 'tv'], 'langs' => ['English'], 'fast' => true],
        ['key' => 'hemera', 'label' => 'Hemera', 'path' => 'Hemera', 'kinds' => ['movie', 'tv'], 'langs' => ['English', 'Hindi', 'Gujarati'], 'hidden' => true],
        ['key' => 'moviesapi', 'label' => 'Hemera', 'path' => 'Perses', 'kinds' => ['movie', 'tv'], 'langs' => ['English'], 'fast' => true],
        ['key' => 'poseidon', 'label' => 'Selene', 'path' => 'Poseidon', 'kinds' => ['movie', 'tv'], 'langs' => ['English'], 'fast' => true],
        ['key' => 'mnemosyne', 'label' => 'Mnemosyne', 'path' => 'Mnemosyne', 'kinds' => ['movie', 'tv'], 'langs' => ['German'], 'fast' => true],
        ['key' => 'hades', 'label' => 'Nyx', 'path' => 'Hades', 'kinds' => ['movie', 'tv'], 'langs' => ['Italian'], 'fast' => true],
        ['key' => 'athena', 'label' => 'Athena', 'path' => 'Athena', 'kinds' => ['movie', 'tv'], 'langs' => ['Portuguese'], 'fast' => true],
        ['key' => 'morpheus', 'label' => 'Morpheus', 'path' => 'Morpheus', 'kinds' => ['movie', 'tv'], 'langs' => ['Portuguese'], 'fast' => true],
        ['key' => 'hephaestus', 'label' => 'Hephaestus', 'path' => 'Hephaestus', 'kinds' => ['movie', 'tv'], 'langs' => ['French'], 'fast' => true],
        ['key' => 'styx', 'label' => 'Notus', 'path' => 'Styx', 'kinds' => ['movie', 'tv'], 'langs' => ['French'], 'fast' => true],
        ['key' => 'aphrodite', 'label' => 'Aphrodite', 'path' => 'Aphrodite', 'kinds' => ['movie', 'tv', 'anime'], 'langs' => ['Spanish']],
        ['key' => 'cronus', 'label' => 'Cronus', 'path' => 'Cronus', 'kinds' => ['movie', 'tv'], 'langs' => ['German']],
        ['key' => 'coeus', 'label' => 'Coeus', 'path' => 'Coeus', 'kinds' => ['movie', 'tv'], 'langs' => ['German']],
        ['key' => 'hecate', 'label' => 'Hecate', 'path' => 'Hecate', 'kinds' => ['movie', 'tv'], 'langs' => ['Spanish']],
        ['key' => 'crius', 'label' => 'Crius', 'path' => 'Crius', 'kinds' => ['movie', 'tv'], 'langs' => ['English']],
        ['key' => 'astraeus', 'label' => 'Astraeus', 'path' => 'Astraeus', 'kinds' => ['movie', 'tv', 'anime'], 'langs' => ['English']],
        ['key' => 'demeter', 'label' => 'Demeter', 'path' => 'Demeter', 'kinds' => ['tv'], 'langs' => ['English']],
        ['key' => 'demeter2', 'label' => 'Demeter 2', 'path' => 'Demeter', 'kinds' => ['tv'], 'langs' => ['English']],
        ['key' => 'demeter3', 'label' => 'Demeter 3', 'path' => 'Demeter', 'kinds' => ['tv'], 'langs' => ['English']],
        ['key' => 'eris', 'label' => 'Eris', 'path' => 'Eris', 'kinds' => ['anime'], 'langs' => ['Japanese', 'English']],
        ['key' => 'rhea', 'label' => 'Rhea', 'path' => 'Rhea', 'kinds' => ['anime'], 'langs' => ['Japanese', 'English'], 'fast' => true],
        ['key' => 'metisbonk', 'label' => 'Icarus', 'path' => 'Metis', 'kinds' => ['anime'], 'langs' => ['Japanese'], 'fast' => true],
        ['key' => 'metis', 'label' => 'Metis', 'path' => 'Metis', 'kinds' => ['anime'], 'langs' => ['Japanese', 'English']],
        ['key' => 'metis_ally', 'label' => 'Talos', 'path' => 'Metis', 'kinds' => ['anime'], 'langs' => ['Japanese'], 'fast' => true],
        ['key' => 'metis_hop', 'label' => 'Proteus', 'path' => 'Metis', 'kinds' => ['anime'], 'langs' => ['Japanese'], 'fast' => true],
        ['key' => 'metis_moo', 'label' => 'Glaucus', 'path' => 'Metis', 'kinds' => ['anime'], 'langs' => ['Japanese'], 'fast' => true],
        ['key' => 'metis_flaky', 'label' => 'Argus', 'path' => 'Metis', 'kinds' => ['anime'], 'langs' => ['Japanese']],
        ['key' => 'hyperion', 'label' => 'Hyperion', 'path' => 'Hyperion', 'kinds' => ['anime'], 'langs' => ['Japanese', 'English', 'German'], 'fast' => true],
        ['key' => 'iapetus', 'label' => 'Iapetus', 'path' => 'Iapetus', 'kinds' => ['anime'], 'langs' => ['Japanese', 'English', 'Hindi', 'Tamil', 'Telugu', 'Malayalam']],
        ['key' => 'nyx', 'label' => 'Thanatos', 'path' => 'Nyx', 'kinds' => ['anime'], 'langs' => ['German'], 'fast' => true],
        ['key' => 'asteria', 'label' => 'Asteria', 'path' => 'Asteria', 'kinds' => ['anime'], 'langs' => ['Japanese', 'Italian'], 'fast' => true],
        ['key' => 'tethys', 'label' => 'Tethys', 'path' => 'Tethys', 'kinds' => ['anime'], 'langs' => ['Japanese', 'Italian'], 'fast' => true],
        ['key' => 'erebus', 'label' => 'Erebus', 'path' => 'Erebus', 'kinds' => ['anime'], 'langs' => ['Japanese', 'French'], 'fast' => true],
        ['key' => 'heracles', 'label' => 'Eos', 'path' => 'Heracles', 'kinds' => ['anime'], 'langs' => ['French'], 'fast' => true],
        ['key' => 'aether', 'label' => 'Aether', 'path' => 'Aether', 'kinds' => ['anime'], 'langs' => ['Japanese', 'Portuguese'], 'fast' => true],
        ['key' => 'tartarus', 'label' => 'Surya', 'path' => 'Tartarus', 'kinds' => ['anime'], 'langs' => ['Hindi'], 'fast' => true],
        ['key' => 'calypso', 'label' => 'Calypso', 'path' => 'Calypso', 'kinds' => ['anime'], 'langs' => ['Japanese', 'Portuguese']],
        ['key' => 'tyche', 'label' => 'Tyche', 'path' => 'Tyche', 'kinds' => ['anime'], 'langs' => ['Japanese', 'Spanish'], 'hidden' => true],
        ['key' => 'indra', 'label' => 'Indra', 'path' => 'Toonstream', 'kinds' => ['anime'], 'langs' => ['Hindi']],
    ];

    /**
     * @param  array<string,mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $config = array_merge(config('diostream'), $config);

        $this->base = (string) $config['base'];
        $this->aesKey = (string) $config['aes_key'];
        $this->timeout = (int) $config['timeout'];
        $this->cacheTtl = (int) $config['cache_ttl'];
        $this->paceMs = (int) $config['pace_ms'];
        $this->rateRetries = (int) $config['rate_retries'];
        $this->userAgent = (string) $config['user_agent'];
        $this->referer = (string) $config['referer'];
        $this->defaultSource = (string) $config['default_source'];
        $this->fallbackSource = (string) $config['fallback_source'];
    }

    /**
     * The bundled source table.
     *
     * @return list<array<string,mixed>>
     */
    public function sources(): array
    {
        return static::SOURCES;
    }

    /**
     * A single source entry by its key.
     *
     * @return array<string,mixed>|null
     */
    public function source(string $key): ?array
    {
        foreach (static::SOURCES as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Source keys usable for a media type, in "fast first" order (hidden
     * sources are skipped unless $includeHidden).
     *
     * @return list<string>
     */
    public function sourceKeysFor(string $kind, bool $includeHidden = false): array
    {
        $keys = [];
        foreach (static::SOURCES as $s) {
            if (! in_array($kind, $s['kinds'], true)) {
                continue;
            }
            if (! $includeHidden && ! empty($s['hidden'])) {
                continue;
            }
            $keys[] = $s['key'];
        }
        usort($keys, fn ($a, $b) => $this->sourceRank($a) <=> $this->sourceRank($b));

        return $keys;
    }

    /**
     * Rank used to order sources: fast first, then language coverage, then name.
     */
    protected function sourceRank(string $key): int
    {
        $s = $this->source($key);
        if ($s === null) {
            return PHP_INT_MAX;
        }

        $langRank = array_search('French', $s['langs'], true) !== false ? 0 : 1;

        return ($s['fast'] ?? false ? 0 : 1) * 10 + $langRank;
    }

    // Catalog (AES-256-GCM encrypted) ---------------------------------

    /**
     * Search the catalog.
     *
     * @return array<string,mixed> decrypted raw response
     */
    public function search(string $query, string $type = 'all', string $sort = 'popular'): array
    {
        return $this->catalog('/metadata/search', [
            'q' => $query,
            'type' => $type,
            'sort' => $sort,
        ]);
    }

    /**
     * Movie metadata (TMDB-shaped).
     *
     * @return array<string,mixed> decrypted raw response
     */
    public function movie(string $tmdb): array
    {
        return $this->catalog('/metadata/movie/'.urlencode($tmdb));
    }

    /**
     * Series metadata (TMDB-shaped) with seasons/episodes when requested.
     *
     * @return array<string,mixed> decrypted raw response
     */
    public function tv(string $tmdb, bool $episodes = true): array
    {
        return $this->catalog('/metadata/tv/'.urlencode($tmdb), $episodes ? ['episodes' => 'true'] : []);
    }

    /**
     * Browse a catalog section (paginated). Known categories: `movies`,
     * `anime`. Pages carry up to 20 (movies) / 30 (anime) items.
     *
     * @return array<string,mixed> decrypted raw response
     */
    public function browse(string $category, int $page = 1): array
    {
        return $this->catalog('/library/browse/'.$category, ['page' => $page]);
    }

    /**
     * Latest released episodes feed.
     *
     * @return array<string,mixed> decrypted raw response
     */
    public function latestEpisodes(int $page = 1): array
    {
        return $this->catalog('/library/latest/episodes', ['page' => $page]);
    }

    /**
     * Fetch + decrypt a catalog endpoint.
     *
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    protected function catalog(string $path, array $query = []): array
    {
        $cacheKey = 'diostream:catalog:'.md5($path.'?'.http_build_query($query));
        if ($this->cacheTtl > 0 && ($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        $body = $this->request($path, $query);
        if ($body === null) {
            throw new DioStreamException('DioStream request failed: '.$path);
        }

        $data = $this->decrypt($body);
        if ($this->cacheTtl > 0) {
            Cache::put($cacheKey, $data, $this->cacheTtl);
        }

        return $data;
    }

    /**
     * Decrypt an AES-256-GCM payload: base64([12-byte IV || ct || 16-byte tag]).
     *
     * @return array<string,mixed>
     */
    public function decrypt(string $payload): array
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new DioStreamException('DioStream: malformed encrypted payload');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $ct = substr($raw, 12, -16);

        $pt = openssl_decrypt($ct, 'aes-256-gcm', hex2bin($this->aesKey), OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            throw new DioStreamException('DioStream: AES-GCM decryption failed (key rotated?)');
        }

        $data = json_decode($pt, true);
        if (! is_array($data)) {
            throw new DioStreamException('DioStream: decrypted payload is not JSON');
        }

        return $data;
    }

    // Streams (plaintext) ---------------------------------------------

    /**
     * Stream sources for a movie (source list is tried in order until one
     * returns providers).
     *
     * @return array<string,mixed> raw payload: providers/sources/subtitles
     */
    public function movieStream(string $tmdb, ?string $sourceKey = null): array
    {
        $keys = $this->streamSourceKeys($sourceKey, 'movie');

        foreach ($keys as $key) {
            $s = $this->source($key);
            $payload = $this->plain($s['path'].'/movie/'.urlencode($tmdb), ['verify' => 'false', 'hevc' => '0']);
            if (! empty($payload['providers'])) {
                return $payload + ['source' => $key];
            }
        }

        return [];
    }

    /**
     * Stream sources for a series episode.
     *
     * @return array<string,mixed> raw payload: providers/sources/subtitles
     */
    public function tvStream(string $tmdb, int $season, int $episode, ?string $sourceKey = null): array
    {
        $keys = $this->streamSourceKeys($sourceKey, 'tv');

        foreach ($keys as $key) {
            $s = $this->source($key);
            $payload = $this->plain($s['path'].'/tv/'.urlencode($tmdb).'/'.$season.'/'.$episode, ['verify' => 'false', 'hevc' => '0']);
            if (! empty($payload['providers'])) {
                return $payload + ['source' => $key];
            }
        }

        return [];
    }

    /**
     * Stream sources for an anime episode (ids are AniList/MAL ids, not TMDB).
     *
     * @return array<string,mixed> raw payload: providers/sources/subtitles
     */
    public function animeStream(string $anilistId, ?int $malId, int $episode, ?string $sourceKey = null): array
    {
        $keys = $this->streamSourceKeys($sourceKey, 'anime');

        foreach ($keys as $key) {
            $s = $this->source($key);
            $query = ['verify' => 'false', 'hevc' => '0'];
            if ($malId !== null && $malId > 0) {
                $query['mal'] = $malId;
            }
            $payload = $this->plain($s['path'].'/anime/'.urlencode($anilistId).'/'.$episode, $query);
            if (! empty($payload['providers'])) {
                return $payload + ['source' => $key];
            }
        }

        return [];
    }

    /**
     * Single-source stream fetch (no fallback walk) — used by the snapshot /
     * upload pipelines to probe sources one by one and pick by language.
     *
     * @return array<string,mixed> raw payload
     */
    public function movieStreamFrom(string $tmdb, string $sourceKey): array
    {
        $s = $this->source($sourceKey);
        if ($s === null || ! in_array('movie', $s['kinds'], true)) {
            return [];
        }

        return $this->plain($s['path'].'/movie/'.urlencode($tmdb), ['verify' => 'false', 'hevc' => '0']);
    }

    /**
     * @return array<string,mixed> raw payload
     */
    public function tvStreamFrom(string $tmdb, int $season, int $episode, string $sourceKey): array
    {
        $s = $this->source($sourceKey);
        if ($s === null || ! in_array('tv', $s['kinds'], true)) {
            return [];
        }

        return $this->plain($s['path'].'/tv/'.urlencode($tmdb).'/'.$season.'/'.$episode, ['verify' => 'false', 'hevc' => '0']);
    }

    /**
     * @return array<string,mixed> raw payload
     */
    public function animeStreamFrom(string $anilistId, ?int $malId, int $episode, string $sourceKey): array
    {
        $s = $this->source($sourceKey);
        if ($s === null || ! in_array('anime', $s['kinds'], true)) {
            return [];
        }
        $query = ['verify' => 'false', 'hevc' => '0'];
        if ($malId !== null && $malId > 0) {
            $query['mal'] = $malId;
        }

        return $this->plain($s['path'].'/anime/'.urlencode($anilistId).'/'.$episode, $query);
    }

    /**
     * Anime metadata (AniList-shaped). `episodes` is the total count; the
     * per-season episode list lives under `seasons[].episodes` when requested.
     *
     * @return array<string,mixed> decrypted raw response
     */
    public function anime(string $id, bool $episodes = true): array
    {
        return $this->catalog('/metadata/anime/'.urlencode($id), $episodes ? ['episodes' => 'true'] : []);
    }

    /**
     * Order of source keys to try for a media type: default source first,
     * then the fallback, then the remaining sources for the kind.
     *
     * @return list<string>
     */
    protected function streamSourceKeys(?string $sourceKey, string $kind): array
    {
        $wanted = [$sourceKey, $this->defaultSource, $this->fallbackSource];

        $ordered = [];
        foreach ($wanted as $key) {
            if ($key === null || $key === '') {
                continue;
            }
            $s = $this->source($key);
            if ($s !== null && in_array($kind, $s['kinds'], true) && ! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }
        foreach ($this->sourceKeysFor($kind) as $key) {
            if (! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }

        return $ordered;
    }

    // Normalization -----------------------------------------------------

    /**
     * Normalize a stream payload into the app's `sources` shape
     * (same keys as StreamController::play sources).
     *
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    public function streamSources(array $payload): array
    {
        $out = [];
        foreach (($payload['providers'] ?? []) as $provider) {
            if (! is_array($provider)) {
                continue;
            }
            $providerName = (string) ($provider['name'] ?? $provider['provider'] ?? 'DioStream');
            foreach (($provider['sources'] ?? []) as $s) {
                if (! is_array($s) || empty($s['url'])) {
                    continue;
                }
                $type = strtolower((string) ($s['type'] ?? 'mp4'));
                $quality = (string) ($s['quality'] ?? '');
                $resolution = 0;
                if (preg_match('/(\d{3,4})/', $quality, $m)) {
                    $resolution = (int) $m[1];
                }

                $out[] = [
                    'url' => (string) $s['url'],
                    'resolution' => $resolution,
                    'quality' => $quality !== '' ? $quality : ($type === 'hls' ? 'HLS' : 'Auto'),
                    'format' => $type,
                    'codec' => $type === 'mp4' ? 'h264' : null,
                    'source' => $providerName,
                    'language' => $s['language'] ?? null,
                    'provider' => 'diostream',
                ];
            }
        }

        return $out;
    }

    /**
     * Normalize a stream payload's subtitle list.
     *
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    public function streamSubtitles(array $payload): array
    {
        $out = [];
        foreach (($payload['subtitles'] ?? []) as $s) {
            if (! is_array($s) || empty($s['url'])) {
                continue;
            }
            $out[] = [
                'url' => (string) $s['url'],
                'lang' => (string) ($s['lang'] ?? ''),
                'format' => (string) ($s['format'] ?? 'srt'),
            ];
        }

        return $out;
    }

    /**
     * Metadata normalized into the app's detail `item` shape
     * (same keys as CatalogController::buildDetail items).
     *
     * @return array<string,mixed>
     */
    public function itemDetail(string $tmdb, string $kind = 'auto'): array
    {
        $raw = $this->metadata($tmdb, $kind);

        $isSeries = ($raw['mediaType'] ?? '') !== 'movie'
            || ! empty($raw['number_of_seasons']);

        $title = (string) ($raw['title'] ?? 'Untitled');
        $poster = (string) ($raw['poster'] ?? '');
        $backdrop = (string) ($raw['backdrop'] ?? '');

        return [
            'subjectId' => $tmdb,
            'subjectType' => $isSeries ? 2 : 1,
            'typeLabel' => $isSeries ? 'TV Series' : 'Movies',
            'title' => $title,
            'description' => $raw['overview'] ?? null,
            'cover' => $poster !== '' ? $poster : null,
            'backdrop' => $backdrop !== '' ? $backdrop : null,
            'genres' => array_values(array_filter((array) ($raw['genres'] ?? []), 'is_string')),
            'releaseDate' => $raw['release_date'] ?? $raw['first_air_date'] ?? null,
            'year' => $raw['year'] ?? null,
            'durationSeconds' => isset($raw['runtime']) ? (int) $raw['runtime'] * 60 : null,
            'imdbRating' => isset($raw['rating']['average']) ? (float) $raw['rating']['average'] : null,
            'country' => $raw['origin_country'][0] ?? null,
            'seasonCount' => $raw['number_of_seasons'] ?? null,
            'detailPath' => null,
            'hasResource' => true,
            'tmdb' => $tmdb,
        ];
    }

    /**
     * Series seasons normalized into the app's seasons shape
     * (same keys as CatalogController::normalizeSeasons).
     *
     * @return list<array<string,mixed>>
     */
    public function seasons(string $tmdb): array
    {
        $raw = $this->tv($tmdb, true);

        $out = [];
        foreach (($raw['seasons'] ?? []) as $season) {
            if (! is_array($season)) {
                continue;
            }
            $eps = array_values(array_filter(array_map(
                fn ($e) => is_array($e) ? (int) ($e['episode_number'] ?? 0) : 0,
                $season['episodes'] ?? []
            )));
            $eps = array_values(array_filter($eps, fn ($n) => $n > 0));
            sort($eps);

            $out[] = [
                'season' => (int) ($season['season_number'] ?? 0),
                'episodeCount' => (int) ($season['episode_count'] ?? count($eps)),
                'episodes' => $eps,
                'resolutions' => [],
            ];
        }

        return $out;
    }

    /**
     * Resolve raw metadata for a title, guessing the endpoint from the kind.
     *
     * @return array<string,mixed>
     */
    protected function metadata(string $tmdb, string $kind = 'auto'): array
    {
        if ($kind === 'tv') {
            return $this->tv($tmdb, false);
        }
        if ($kind === 'movie') {
            return $this->movie($tmdb);
        }

        return $this->metadataAuto($tmdb);
    }

    /**
     * @return array<string,mixed>
     */
    protected function metadataAuto(string $tmdb): array
    {
        try {
            $raw = $this->movie($tmdb);
            if (($raw['mediaType'] ?? 'movie') !== 'movie') {
                return $this->tv($tmdb, false);
            }

            return $raw;
        } catch (DioStreamException $e) {
            // Movie endpoint rejected the id — it is likely a series.
            return $this->tv($tmdb, false);
        }
    }

    // HTTP -------------------------------------------------------------

    /**
     * GET an endpoint returning plaintext JSON, pacing requests to stay under
     * the upstream rate limit and retrying on HTTP 429.
     *
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    protected function plain(string $path, array $query = []): array
    {
        $response = $this->fetch($path, $query);

        if ($response->status() === 429 || $response->status() === 404) {
            // Rate-limited or not on this source — treat as "no providers" so
            // the caller can move to the next source / title.
            return [];
        }

        $data = json_decode($response->body(), true);
        if (! is_array($data)) {
            throw new DioStreamException('DioStream: non-JSON response for /'.$path);
        }

        return $data;
    }

    /**
     * GET an endpoint returning the raw body (encrypted or plaintext).
     *
     * @param  array<string,mixed>  $query
     */
    protected function request(string $path, array $query = []): ?string
    {
        return $this->fetch($path, $query)->body();
    }

    /**
     * @param  array<string,mixed>  $query
     */
    protected function fetch(string $path, array $query = []): Response
    {
        $attempts = 1 + $this->rateRetries;

        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                // Back off after a 429 before retrying.
                usleep((int) (($i ** 2) * 1_000_000));
            }
            $this->pace();

            $response = $this->buildClient()
                ->get($this->base.'/'.ltrim($path, '/').(count($query) ? '?'.http_build_query($query) : ''));

            if ($response->status() !== 429 || $i === $attempts - 1) {
                return $response;
            }
        }

        // Unreachable; kept for static analysis.
        throw new DioStreamException('DioStream: rate limited');
    }

    /**
     * Space two consecutive outbound requests by at least `pace_ms`.
     */
    protected function pace(): void
    {
        $now = microtime(true);
        if (static::$lastRequestAt !== null) {
            $elapsed = ($now - static::$lastRequestAt) * 1000;
            if ($elapsed < $this->paceMs) {
                usleep((int) (($this->paceMs - $elapsed) * 1000));
            }
        }
        static::$lastRequestAt = microtime(true);
    }

    protected function buildClient(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => '*/*',
                'Referer' => $this->referer,
                'Origin' => rtrim($this->referer, '/'),
            ])
            ->withoutRedirecting();
    }

    /**
     * Throw a descriptive error when the response is unusable.
     */
    protected function assertOk(Response $response, string $path): void
    {
        if ($response->successful()) {
            return;
        }
        if ($response->status() === 429) {
            throw new DioStreamException('DioStream: rate limited on /'.$path);
        }
        if ($response->status() === 404) {
            throw new DioStreamException('DioStream: not found on /'.$path);
        }
        if ($response->serverError()) {
            throw new DioStreamException('DioStream: HTTP '.$response->status().' on /'.$path);
        }
        throw new DioStreamException('DioStream: HTTP '.$response->status().' on /'.$path);
    }
}
