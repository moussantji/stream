<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DioStream\DioStreamClient;
use App\Services\MovieBox\MovieBoxClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamController extends Controller
{
    public function __construct(
        protected MovieBoxClient $client,
        protected ?DioStreamClient $dio = null
    ) {
        $this->dio ??= app(DioStreamClient::class);
    }

    /**
     * Resolve playable sources for a title. For movies pass season=0 & episode=0;
     * for a series episode pass the season & episode numbers.
     */
    public function play(Request $request): JsonResponse
    {
        $v = $this->validatePayload($request);
        // The debug payload (upstream diagnostics) is only built on an explicit
        // ?debug=1 request, which also bypasses the endpoint cache below.
        $debug = $request->boolean('debug');

        // Endpoint-level cache: the resolution below fans out to several
        // upstream calls; replaying it for the same subject/season/episode is
        // wasteful. TTL stays well under the 2h proxy-token lifetime.
        $ttl = min((int) config('moviebox.snapshot_ttl', 900), 3600);
        $cacheKey = 'stream:play:'.$v['subjectId'].':'.$v['season'].':'.$v['episode'];

        if (! $debug && $ttl > 0 && ($cached = Cache::get($cacheKey)) !== null) {
            return response()->json(['data' => $cached]);
        }

        $diag = [];
        $payload = $this->resolvePlayPayload($v, $diag, $debug);

        // Only cache resolvable results, never "no stream" outcomes that may
        // be transient upstream errors.
        if (! $debug && $ttl > 0 && $payload['hasResource']) {
            Cache::put($cacheKey, $payload, $ttl);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Resolve every playable source for a title (the work behind /api/play).
     *
     * The adaptive `play-info` and the first `resource` page are fetched in a
     * single concurrent round trip (MovieBoxClient::playBundle), halving the
     * cold-start latency.
     *
     * @param  array{subjectId:string,season:int,episode:int,title:string}  $v
     * @param  array<string,mixed>  $diag
     * @return array<string,mixed>
     */
    protected function resolvePlayPayload(array $v, array &$diag, bool $debug): array
    {
        $bundle = $this->client->playBundle($v['subjectId'], $v['season'], $v['episode']);

        $stream = $this->resolvePlayInfo($v['subjectId'], $v['season'], $v['episode'], $diag, $debug, $bundle['playInfo'] ?? null);
        $dash = $stream['dash'];
        $hls = $stream['hls'];
        $subtitles = $stream['subtitles'];

        $meta = null;
        $files = $this->resolveVideoFiles($v['subjectId'], $v['season'], $v['episode'], $diag, $meta, $bundle['resource1'] ?? null);
        $sourceSubjectId = $v['subjectId'];

        // The requested episode isn't in this subject. Many French-dubbed titles
        // split each season/episode into a SEPARATE catalog entry with the
        // season/episode in the title, so search for that sibling and play it.
        if ($files === [] && $v['season'] > 0 && ($v['title'] ?? '') !== '') {
            $sibling = $this->resolveSiblingEpisode($v['title'], $v['season'], $v['episode'], $diag);
            if ($sibling !== null) {
                $files = $sibling['files'];
                $sourceSubjectId = $sibling['subjectId'];
            }
        }

        // Last resort: the subject is genuinely a single video mislabeled with a
        // season. Only serve it for the first episode, never for later seasons
        // (whose videos live under the sibling entries handled above).
        if ($files === [] && $v['season'] > 0
            && $meta && ! $meta['hasEpisodeStructure'] && $meta['firstPageFiles'] !== []
            && $v['season'] <= 1 && $v['episode'] <= 1) {
            $files = $meta['firstPageFiles'];
            $diag['fallback'] = 'movie-single-file';
        }

        $sources = $this->normalizeSources($files);

        // H5 transport: merge its downloadable MP4s (H.264; free tiers cap at
        // 480p, 1080p stays vipLocked) with the mobile sources. When both
        // transports have the same resolution the best codec wins; nothing
        // extra upstream is needed (the H5 API works without a token). The
        // RAW files are merged and normalized in a single pass so proxying
        // and HEVC detection see the original CDN URLs.
        $h5 = $this->resolveH5Download($v['subjectId'], $v['season'], $v['episode'], $diag);
        if ($h5['sources'] !== []) {
            $sources = $this->normalizeSources(array_merge($files, $h5['sources']));
        }

        // A fully-converted Streamtape copy is preferred over the raw CDN
        // files: it plays in any browser without proxying or codec hacks and
        // offloads the upstream CDN. It is injected as the first source, but
        // only while the remote file is confirmed alive (Streamtape purges
        // files at any time), otherwise the CDN sources stay in front.
        if (config('moviebox.streamtape_fallback', false)) {
            $stRow = \App\Models\StreamtapeLink::query()
                ->where('subject_id', $v['subjectId'])
                ->where('season', $v['season'])
                ->where('episode', $v['episode'])
                ->where('status', 'done')
                ->whereNotNull('streamtape_url')
                ->where('streamtape_url', '!=', '')
                ->latest()
                ->first();
            if ($stRow && $this->streamtapeLinkAvailable($stRow)) {
                array_unshift($sources, [
                    'url' => $stRow->streamtape_url,
                    'resolution' => $stRow->resolution,
                    'quality' => ($stRow->resolution ?: 0).'p',
                    'size' => $stRow->bytes_total,
                    'format' => 'streamtape',
                    'codec' => 'h264',
                    'source' => 'streamtape',
                ]);

                // Attach the subtitles captured at upload time (DioStream rows
                // store the FR/EN subtitle URLs; MovieBox rows have none).
                if ($subtitles === [] && is_array($stRow->subtitle_urls)) {
                    $subtitles = $this->normalizeCaptions($stRow->subtitle_urls);
                }
            }
        }

        // Prefer the adaptive subtitles when present, else the MP4's captions.
        if ($subtitles === [] && $files !== []) {
            $subtitles = $this->resolveSubtitles($sourceSubjectId, $files, $diag);
        }
        if ($subtitles === [] && $h5['captions'] !== []) {
            $subtitles = $this->normalizeCaptions($h5['captions']);
        }

        $payload = [
            'sources' => $sources,
            'hls' => $hls,
            'dash' => $dash,
            'subtitles' => $subtitles,
            'hasResource' => $sources !== [] || $hls !== [] || $dash !== [],
        ];

        // When nothing resolved, tell the client WHY so it can distinguish a
        // genuinely unavailable title from a transient upstream failure (which
        // deserves a retry) instead of always showing a dead-end message.
        if (! $payload['hasResource']) {
            $payload['streamError'] = $this->resolutionError($diag);
        }

        // DioStream fallback: when the MovieBox transport found nothing, try
        // the diostream.cc backend (keyed by TMDB id). The subject id is used
        // directly when it is numeric (MovieBox subject ids are TMDB-based);
        // otherwise the title is resolved through the DioStream search.
        if (! $payload['hasResource']) {
            $dioPayload = $this->resolveDioFallback($v, $diag);
            if ($dioPayload !== null) {
                $payload = $dioPayload;
            }
        }

        if ($debug) {
            $payload['debug'] = [
                'params' => $v,
                'host' => $this->client->activeHost(),
                'sourceSubjectId' => $sourceSubjectId,
                'fileCount' => count($files),
                'calls' => $diag,
            ];
        }

        return $payload;
    }

    /**
     * Resolve a playable source through the DioStream backend.
     *
     * @param  array{subjectId:string,season:int,episode:int,title:string}  $v
     * @param  array<string,mixed>  $diag
     * @return array<string,mixed>|null null when nothing resolvable
     */
    protected function resolveDioFallback(array $v, array &$diag): ?array
    {
        try {
            $tmdb = $this->dioTmdbId($v, $diag);
            if ($tmdb === null) {
                return null;
            }

            $raw = $v['season'] > 0
                ? $this->dio->tvStream($tmdb, $v['season'], $v['episode'])
                : $this->dio->movieStream($tmdb);

            if (empty($raw['providers'])) {
                $diag['diostream'] = ['tmdb' => $tmdb, 'error' => 'no providers'];
                return null;
            }

            $sources = $this->dio->streamSources($raw);
            if ($sources === []) {
                $diag['diostream'] = ['tmdb' => $tmdb, 'error' => 'no sources'];
                return null;
            }

            $diag['diostream'] = [
                'tmdb' => $tmdb,
                'source' => $raw['source'] ?? null,
                'streams' => count($sources),
            ];

            return [
                'sources' => $sources,
                'hls' => null,
                'dash' => null,
                'subtitles' => $this->dio->streamSubtitles($raw),
                'hasResource' => true,
                'provider' => 'diostream',
                'streamError' => null,
            ];
        } catch (\Throwable $e) {
            report($e);
            $diag['diostream'] = ['error' => $e->getMessage()];

            return null;
        }
    }

    /**
     * Resolve the TMDB id for a play request: the subject id itself when it is
     * numeric, otherwise the first matching DioStream search result.
     *
     * @param  array{subjectId:string,season:int,episode:int,title:string}  $v
     * @param  array<string,mixed>  $diag
     */
    protected function dioTmdbId(array $v, array &$diag): ?string
    {
        if (ctype_digit((string) $v['subjectId'])) {
            return (string) $v['subjectId'];
        }

        $title = trim((string) ($v['title'] ?? ''));
        if ($title === '') {
            return null;
        }

        $results = $this->dio->search($title)['results'] ?? [];
        $want = $v['season'] > 0 ? 'tv' : 'movie';
        foreach ($results as $r) {
            if (is_array($r) && ($r['media_type'] ?? '') === $want && ! empty($r['tmdb_id'])) {
                return (string) $r['tmdb_id'];
            }
        }
        foreach ($results as $r) {
            if (is_array($r) && in_array($r['media_type'] ?? '', ['movie', 'tv'], true) && ! empty($r['tmdb_id'])) {
                return (string) $r['tmdb_id'];
            }
        }

        $diag['diostream'] = ['error' => 'no tmdb match for title', 'title' => $title];

        return null;
    }

    /**
     * Extract the first upstream error from the diagnostic log, with a retry
     * hint. Returns null when the resolution succeeded but simply found no
     * stream (the title is genuinely unavailable upstream).
     *
     * @param  array<string,mixed>  $diag
     * @return array{message:string,detail:string,retryable:bool}|null
     */
    protected function resolutionError(array $diag): ?array
    {
        $msg = null;
        $retryable = false;

        foreach ($diag as $v) {
            if (! is_array($v) || empty($v['error'])) {
                continue;
            }
            $err = (string) $v['error'];
            $retryable = $retryable
                || str_contains($err, '429')
                || str_contains($err, 'timed out')
                || str_contains($err, 'Connection')
                || str_contains($err, 'unreachable')
                || str_contains($err, 'HTTP 500')
                || str_contains($err, 'HTTP 502')
                || str_contains($err, 'HTTP 503');
            $msg = $msg ?? $err;
        }

        if ($msg === null) {
            return null;
        }

        return [
            'message' => 'Le fournisseur de flux n\'a pas répondu correctement.',
            'detail' => $msg,
            'retryable' => $retryable,
        ];
    }

    /** Downloadable media files + subtitle files. */
    public function download(Request $request): JsonResponse
    {
        $v = $this->validatePayload($request);
        $diag = [];

        $files = $this->resolveVideoFiles($v['subjectId'], $v['season'], $v['episode'], $diag);
        $h5 = $this->resolveH5Download($v['subjectId'], $v['season'], $v['episode'], $diag);

        return response()->json([
            'data' => [
                'downloads' => $this->normalizeSources(array_merge($files, $h5['sources'])),
                'subtitles' => $h5['captions'] !== []
                    ? $this->normalizeCaptions($h5['captions'])
                    : $this->resolveSubtitles($v['subjectId'], $files, $diag),
                'hasResource' => $files !== [] || $h5['sources'] !== [],
            ],
        ]);
    }

    /**
     * Downloadable H5 sources (MP4s at every free resolution) plus captions
     * for a subject/season/episode. The transport is optional: any failure
     * here is logged in $diag and treated as "no H5 sources" — callers fall
     * back to the signed mobile API untouched.
     *
     * @param  array<string,mixed>  $diag
     * @return array{sources:array<int,array<string,mixed>>,captions:array<int,mixed>}
     */
    protected function resolveH5Download(string $subjectId, int $season, int $episode, array &$diag): array
    {
        $out = ['sources' => [], 'captions' => []];

        $data = ['downloads' => [], 'captions' => []];
        try {
            $data = $this->client->h5Download($subjectId, $season, $episode);
        } catch (\Throwable $e) {
            report($e);
            $diag['h5Download'] = ['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()];
        }

        $out['captions'] = is_array($data['captions'] ?? null) ? $data['captions'] : [];

        // `subject/play` complements the download endpoint: same direct MP4s
        // (H.264), but its free tier is not capped at 480p — 1080p streams
        // come back unlocked. Merge both and let normalizeSources keep the
        // best codec per resolution (identical URLs dedupe naturally).
        try {
            $play = $this->client->h5Play($subjectId, $season, $episode);
            $diag['h5Play'] = ['ok' => true, 'sources' => count($play['downloads'])];
            foreach (is_array($play['downloads'] ?? null) ? $play['downloads'] : [] as $d) {
                $data['downloads'][] = $d;
            }
        } catch (\Throwable $e) {
            report($e);
            $diag['h5Play'] = ['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()];
        }

        foreach (is_array($data['downloads'] ?? null) ? $data['downloads'] : [] as $d) {
            $resolution = (int) ($d['resolution'] ?? 0);
            $out['sources'][] = [
                'url' => (string) $d['url'],
                'resolution' => $resolution,
                'quality' => $resolution > 0 ? $resolution.'p' : 'auto',
                'size' => isset($d['size']) ? (int) $d['size'] : null,
                'format' => 'mp4',
                'codecName' => $d['codecName'] ?? null,
                'durationSeconds' => isset($d['duration']) ? (int) $d['duration'] : null,
            ];
        }

        $diag['h5Download'] = [
            'ok' => true,
            'sources' => count($out['sources']),
            'captions' => count($out['captions']),
        ];

        return $out;
    }

    /**
     * Fetch the resource list and return the video files matching the requested
     * season/episode (all resolution variants for a movie).
     *
     * The first page may be passed in pre-fetched from the concurrent
     * `playBundle` round trip, avoiding a second sequential upstream call.
     *
     * @param  array<string,mixed>  $diag
     * @param  array<string,mixed>|null  $meta
     * @param  array<string,mixed>|null  $firstPage
     * @return array<int,array<string,mixed>>
     */
    protected function resolveVideoFiles(string $subjectId, int $season, int $episode, array &$diag, ?array &$meta = null, ?array $firstPage = null): array
    {
        $isMovie = $season === 0 && $episode === 0;
        // `resource` returns a flat list of every episode across all seasons,
        // 20 per page (the API caps perPage at 20 — larger values are rejected),
        // but only ONE resolution tier per request. Hardcoding a single tier
        // hides whole seasons whenever that tier is missing (e.g. From S4 VF
        // has no 1080p except S4E3 — the whole season only exists at 480p),
        // so every tier is probed and the matches merged.
        $maxPages = $isMovie ? 1 : 40;
        $matched = [];
        $firstPageFiles = [];      // used to detect / play a single-video subject
        $hasEpisodeStructure = false;

        foreach ([1080, 720, 480, 360] as $tier) {
            $page = 1;
            do {
                if ($page === 1 && $tier === 1080 && $firstPage !== null) {
                    $res = $firstPage;
                } else {
                    try {
                        $res = $this->client->resource($subjectId, $tier, $page, 20);
                    } catch (\Throwable $e) {
                        report($e);
                        $diag["resource_{$tier}_page_$page"] = ['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()];
                        break;
                    }
                }

                $list = is_array($res['list'] ?? null) ? $res['list'] : [];

                // Debug sample: what se/ep does each file carry? ("*" = has a link)
                $seEpSample = [];
                foreach ($list as $it) {
                    if (is_array($it)) {
                        $seEpSample[] = 's'.($it['se'] ?? '?').'e'.($it['ep'] ?? '?')
                            .(empty($it['resourceLink']) ? '' : '*');
                    }
                }

                $diag["resource_{$tier}_page_$page"] = [
                    'ok' => true,
                    'listCount' => count($list),
                    'hasMore' => $res['pager']['hasMore'] ?? false,
                    'seEp' => $seEpSample,
                ];

                if ($isMovie) {
                    $matched = array_values(array_filter(
                        $list,
                        fn ($it) => is_array($it) && ! empty($it['resourceLink'])
                    ));
                    break 2;
                }

                foreach ($list as $it) {
                    if (! is_array($it) || empty($it['resourceLink'])) {
                        continue;
                    }

                    $se = (int) ($it['se'] ?? 0);
                    $ep = (int) ($it['ep'] ?? 0);

                    if ($se > 0 || $ep > 0) {
                        $hasEpisodeStructure = true;
                    }
                    if ($page === 1 && $tier === 1080) {
                        $firstPageFiles[] = $it;
                    }
                    if ($se === $season && $ep === $episode) {
                        $matched[] = $it;
                    }
                }

                $hasMore = (bool) ($res['pager']['hasMore'] ?? false);
                $page++;
            } while ($matched === [] && $hasMore && $page <= $maxPages);
        }

        $meta = ['hasEpisodeStructure' => $hasEpisodeStructure, 'firstPageFiles' => $firstPageFiles];

        return $matched;
    }

    /**
     * Find the video for a season/episode that lives under a SEPARATE catalog
     * entry (common for French-dubbed titles where each season/episode is
     * uploaded as its own subject, e.g. "From Saison 4 [Version française]").
     *
     * @param  array<string,mixed>  $diag
     * @return array{subjectId:string,files:array<int,array<string,mixed>>}|null
     */
    protected function resolveSiblingEpisode(string $title, int $season, int $episode, array &$diag): ?array
    {
        $base = $this->baseTitle($title);
        if ($base === '') {
            return null;
        }

        $queries = array_values(array_unique(array_filter([
            "{$base} saison {$season} episode {$episode}",
            "{$base} saison {$season}",
            "{$base} season {$season}",
            sprintf('%s s%02de%02d', $base, $season, $episode),
            "{$base} {$season}",
        ])));

        $tried = [];
        foreach ($queries as $query) {
            try {
                $res = $this->client->search($query, 0, 1, 20);
            } catch (\Throwable $e) {
                report($e);

                continue;
            }

            $items = is_array($res['items'] ?? null) ? $res['items'] : [];
            $match = $this->pickSiblingMatch($items, $base, $season, $episode);
            $tried[] = ['q' => $query, 'results' => count($items), 'matched' => $match['subjectId'] ?? null];

            if ($match === null) {
                continue;
            }

            $sid = (string) $match['subjectId'];
            $sibDiag = [];
            // The sibling might itself be episode-structured or a single video.
            $files = $this->resolveVideoFiles($sid, $season, $episode, $sibDiag);
            if ($files === []) {
                $files = $this->resolveVideoFiles($sid, 0, 0, $sibDiag);
            }

            if ($files !== []) {
                $diag['sibling'] = ['queries' => $tried, 'used' => $sid, 'title' => $match['title'] ?? null];

                return ['subjectId' => $sid, 'files' => $files];
            }
        }

        $diag['sibling'] = ['queries' => $tried, 'used' => null];

        return null;
    }

    /** Strip version / season / episode qualifiers to get the core show title. */
    protected function baseTitle(string $title): string
    {
        $t = mb_strtolower($title);
        $t = preg_replace('/[\[\(].*?[\]\)]/u', ' ', $t);                              // [..] (..)
        $t = preg_replace('/\b(version\s+fran[cç]aise|vf|vostfr|vost|vo|multi|truefrench|french)\b/u', ' ', $t);
        $t = preg_replace('/\bsaisons?\s*\d+\b/u', ' ', $t);
        $t = preg_replace('/\b(episode|épisode|ep)\s*\d+\b/u', ' ', $t);
        $t = preg_replace('/\bs\d{1,2}\s*e\d{1,3}\b/u', ' ', $t);
        $t = preg_replace('/\bs\d{1,2}\b/u', ' ', $t);
        $t = preg_replace('/\s+/u', ' ', (string) $t);

        return trim((string) $t);
    }

    /**
     * Pick the best sibling result: the base title must be present and the
     * requested season must be referenced; a matching episode boosts the score,
     * a different season penalises it.
     *
     * @param  array<int,mixed>  $items
     * @return array<string,mixed>|null
     */
    protected function pickSiblingMatch(array $items, string $base, int $season, int $episode): ?array
    {
        $baseWords = array_values(array_filter(explode(' ', $base), fn ($w) => mb_strlen($w) >= 2));
        $best = null;
        $bestScore = 0;

        foreach ($items as $it) {
            if (! is_array($it) || empty($it['subjectId'])) {
                continue;
            }
            $t = mb_strtolower((string) ($it['title'] ?? ''));
            if ($t === '') {
                continue;
            }

            foreach ($baseWords as $w) {
                if (mb_strpos($t, $w) === false) {
                    continue 2; // base title not present -> skip
                }
            }

            $score = 1;
            $seasonHit = preg_match('/\b(saison|season|s)\s*0*'.$season.'\b/u', $t)
                || preg_match('/\bs0*'.$season.'e\d/u', $t);
            if ($seasonHit) {
                $score += 3;
            }
            if (preg_match('/\b(episode|épisode|ep|e)\s*0*'.$episode.'\b/u', $t)) {
                $score += 2;
            }
            if (preg_match_all('/\bsaisons?\s*(\d+)\b/u', $t, $m)) {
                foreach ($m[1] as $s) {
                    if ((int) $s !== $season) {
                        $score -= 2;
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $it;
            }
        }

        // Require at least the base title + a season reference to be confident.
        return ($best !== null && $bestScore >= 4) ? $best : null;
    }

    /**
     * Adaptive streaming fallback via the play-info endpoint. Extracts MP4,
     * HLS (m3u8) and DASH (mpd) URLs; CloudFront-cookie-protected DASH/HLS is
     * routed through our signing proxy so the browser can play it (no CORS /
     * cookie issues).
     *
     * @param  array<string,mixed>  $diag
     * @param  array<string,mixed>|null  $prefetched  play-info data already fetched by playBundle
     * @return array{sources:array<int,array<string,mixed>>,hls:array<int,string>,dash:array<int,string>,subtitles:array<int,array<string,mixed>>}
     */
    protected function resolvePlayInfo(string $subjectId, int $season, int $episode, array &$diag, bool $debug = false, ?array $prefetched = null): array
    {
        $out = ['sources' => [], 'hls' => [], 'dash' => [], 'subtitles' => []];

        if ($prefetched === null) {
            try {
                $data = $this->client->playInfo($subjectId, $season, $episode);
            } catch (\Throwable $e) {
                report($e);
                $diag['playInfo'] = ['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()];

                return $out;
            }
        } else {
            $data = $prefetched;
        }

        if (! is_array($data)) {
            return $out;
        }

        if ($debug) {
            $diag['playInfoRaw'] = $data;
        }

        // Gather stream entries from whichever container key is present.
        $streams = [];
        foreach (['streams', 'list', 'resources', 'playInfos', 'medias', 'urls', 'playInfo'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $streams = array_merge($streams, array_is_list($data[$key]) ? $data[$key] : [$data[$key]]);
            }
        }
        if ($streams === []) {
            $streams[] = $data;
        }

        $hls = [];
        $dash = [];
        $mp4 = [];
        foreach ($streams as $s) {
            if (! is_array($s)) {
                continue;
            }
            $url = null;
            foreach (['url', 'playUrl', 'streamUrl', 'm3u8', 'hlsUrl', 'link', 'videoUrl', 'mpd'] as $f) {
                if (! empty($s[$f]) && is_string($s[$f])) {
                    $url = $s[$f];
                    break;
                }
            }
            if (! $url) {
                continue;
            }

            $resolution = (int) ($s['resolution'] ?? $s['resolutions'] ?? $s['quality'] ?? 0);
            $format = strtoupper((string) ($s['format'] ?? $s['streamType'] ?? ''));
            $signCookie = (string) ($s['signCookie'] ?? '');

            if (stripos($url, '.mpd') !== false || $format === 'DASH') {
                $proxied = $this->proxifyStream($url, $signCookie);
                if ($proxied !== null) {
                    $dash[] = $proxied;
                }
            } elseif (stripos($url, '.m3u8') !== false || $format === 'HLS') {
                // Route cookie-protected HLS through the proxy too; otherwise
                // it is directly playable by hls.js.
                $hls[] = $signCookie !== '' ? ($this->proxifyStream($url, $signCookie) ?? $url) : $url;
            } elseif (stripos($url, '.mp4') !== false || $format === 'MP4') {
                $mp4[] = ['resourceLink' => $url, 'resolution' => $resolution];
            }
        }

        $out['hls'] = array_values(array_unique(array_filter($hls)));
        $out['dash'] = array_values(array_unique(array_filter($dash)));
        $out['sources'] = $this->normalizeSources($mp4);

        $subs = $data['subtitles'] ?? $data['captions'] ?? $data['extCaptions'] ?? [];
        if (is_array($subs)) {
            $out['subtitles'] = $this->normalizeCaptions($subs);
        }

        return $out;
    }

    /**
     * Build a same-origin proxy URL for a CloudFront-signed manifest so the
     * browser can fetch the manifest AND its segments through us (the signature
     * — a wildcard over the whole folder — is attached server-side).
     */
    protected function proxifyStream(string $url, string $signCookie): ?string
    {
        $parts = parse_url($url);
        if (empty($parts['host']) || empty($parts['path'])) {
            return null;
        }

        // Signature query: prefer the signed cookie, else reuse the URL's query.
        $query = '';
        $ttl = 7200;
        if ($signCookie !== '') {
            $cookie = $this->parseSignCookie($signCookie);
            $query = 'Policy='.($cookie['CloudFront-Policy'] ?? '')
                .'&Signature='.($cookie['CloudFront-Signature'] ?? '')
                .'&Key-Pair-Id='.($cookie['CloudFront-Key-Pair-Id'] ?? '');
            $ttl = $this->signatureTtl($cookie['CloudFront-Policy'] ?? '');
        } elseif (! empty($parts['query'])) {
            $query = $parts['query'];
        }

        $token = Str::random(28);
        Cache::put("mvsig:$token", ['host' => $parts['host'], 'query' => $query], $ttl);

        return url('/api/mv/'.$token.'/'.ltrim($parts['path'], '/'));
    }

    /**
     * Proxy a manifest/segment from the media CDN, attaching the CloudFront
     * signature stored under $token. Manifests are rewritten so any absolute
     * CDN URLs also flow back through this proxy.
     */
    public function proxy(Request $request, string $token, string $path): Response|StreamedResponse
    {
        $sig = Cache::get("mvsig:$token");
        abort_unless(is_array($sig), 404, 'Stream link expired — reload the page.');

        $host = (string) $sig['host'];
        $allowed = false;
        foreach ((array) config('moviebox.cdn_proxy_allow', []) as $suffix) {
            if ($suffix !== '' && str_ends_with($host, $suffix)) {
                $allowed = true;
                break;
            }
        }
        abort_unless($allowed, 403, 'Host not allowed.');

        $target = 'https://'.$host.'/'.ltrim($path, '/');
        if (($sig['query'] ?? '') !== '') {
            $target .= '?'.$sig['query'];
        }

        $headers = ['Access-Control-Allow-Origin' => '*'];
        $isManifest = str_ends_with($path, '.mpd') || str_ends_with($path, '.m3u8');

        // Manifests (MPD / m3u8): tiny and static per title — cache the raw
        // upstream body so a replay skips the CDN round-trip. The token-specific
        // URL rewrite and the hev1 -> hvc1 codec patch are applied per request
        // after cache retrieval.
        if ($isManifest) {
            $body = Cache::remember('mvm:'.sha1($target), 600, function () use ($target) {
                $res = $this->fetchProxy($target, null);
                abort_if($res->failed(), 502, 'Upstream media fetch failed.');

                return $res->body();
            });

            $body = str_replace('https://'.$host.'/', url('/api/mv/'.$token).'/', $body);
            if (str_ends_with($path, '.mpd')) {
                $body = str_replace('hev1', 'hvc1', $body);
            }

            return response($body, 200, $headers + [
                'Content-Type' => str_ends_with($path, '.m3u8')
                    ? 'application/vnd.apple.mpegurl'
                    : 'application/dash+xml',
                'Cache-Control' => 'no-store',
            ]);
        }

        // Init segments are tiny and static per rendition — cache them too.
        // They are binary, so base64-encode before storing (the DB cache store
        // holds TEXT values only).
        if (str_contains($path, 'init')) {
            $body = base64_decode((string) Cache::remember('mvi:'.sha1($target), 300, function () use ($target, $request) {
                $res = $this->fetchProxy($target, $request->header('Range'));
                abort_if($res->failed(), 502, 'Upstream media fetch failed.');

                return base64_encode($res->body());
            }), true);

            return response(str_replace('hev1', 'hvc1', $body), 200, $headers + [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) strlen($body),
                'Cache-Control' => 'public, max-age=120',
            ]);
        }

        // Media chunks / segments: stream them from the CDN to the browser so
        // the first bytes arrive as soon as the CDN sends them (buffering the
        // whole segment in PHP delayed the first frame). The sample-entry
        // fourcc lives in the init segment — patched and cached separately
        // above — so media chunks are streamed raw. Range requests are passed
        // through, so seeking still works.
        $range = $request->header('Range');
        $upstream = Http::withOptions(['stream' => true])
            ->withHeaders(array_filter([
                'User-Agent' => config('moviebox.user_agent'),
                'Range' => $range,
            ]))
            ->timeout((int) config('moviebox.timeout', 30))
            ->get($target);

        abort_if($upstream->failed(), 502, 'Upstream media fetch failed.');

        $headers['Content-Type'] = $upstream->header('Content-Type') ?: 'application/octet-stream';
        foreach (['Content-Range', 'Accept-Ranges', 'Content-Length'] as $h) {
            $val = $upstream->header($h);
            if ($val !== '') {
                $headers[$h] = $val;
            }
        }
        $headers['Cache-Control'] = 'public, max-age=120';

        $body = $upstream->toPsrResponse()->getBody();

        return response()->stream(function () use ($body) {
            @set_time_limit(0);
            while (true) {
                try {
                    $chunk = $body->read(65536);
                } catch (\Throwable $e) {
                    break;
                }
                if ($chunk === '') {
                    break;
                }
                echo $chunk;
                if (connection_aborted()) {
                    break;
                }
            }
        }, $upstream->status(), $headers);
    }

    /**
     * Fetch a proxied media resource from the CDN (User-Agent + optional Range).
     */
    protected function fetchProxy(string $target, ?string $range): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders(array_filter([
            'User-Agent' => config('moviebox.user_agent'),
            'Range' => $range,
        ]))->timeout((int) config('moviebox.timeout', 30))->get($target);
    }

    /**
     * @return array<string,string>
     */
    protected function parseSignCookie(string $signCookie): array
    {
        $out = [];
        foreach (explode(';', $signCookie) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            [$k, $val] = array_pad(explode('=', $pair, 2), 2, '');
            $out[trim($k)] = $val;
        }

        return $out;
    }

    /** Seconds until a CloudFront policy expires (DateLessThan), with a floor. */
    protected function signatureTtl(string $policyB64): int
    {
        $json = base64_decode(strtr($policyB64, '-_~', '+/='), true);
        if ($json !== false) {
            $data = json_decode($json, true);
            $exp = $data['Statement'][0]['Condition']['DateLessThan']['AWS:EpochTime'] ?? null;
            if (is_numeric($exp)) {
                return max(60, (int) $exp - time());
            }
        }

        return 7200;
    }

    /**
     * Collect subtitles: embedded on the file, otherwise via the ext-captions
     * endpoint for the first resource.
     *
     * @param  array<int,array<string,mixed>>  $files
     * @param  array<string,mixed>  $diag
     * @return array<int,array<string,mixed>>
     */
    protected function resolveSubtitles(string $subjectId, array $files, array &$diag): array
    {
        foreach ($files as $file) {
            if (! empty($file['extCaptions']) && is_array($file['extCaptions'])) {
                return $this->normalizeCaptions($file['extCaptions']);
            }
        }

        $resourceId = $files[0]['resourceId'] ?? null;
        if (! $resourceId) {
            return [];
        }

        try {
            $cap = $this->client->extCaptions($subjectId, (string) $resourceId);

            return $this->normalizeCaptions($cap['extCaptions'] ?? $cap['captions'] ?? []);
        } catch (\Throwable $e) {
            report($e);
            $diag['captions'] = ['ok' => false, 'error' => $e->getMessage()];

            return [];
        }
    }

    /**
     * @param  array<int,mixed>  $files
     * @return array<int,array<string,mixed>>
     */
    /**
     * Whether the remote Streamtape file behind a `done` row is still alive.
     *
     * `file/info` is NOT a stable oracle: under heavy Streamtape load it
     * intermittently answers `404` for perfectly healthy files, and purged
     * files can alternate between `404` and `200` with a zero size / missing
     * thumbnail. A link therefore only counts as available when a positive
     * record (200 + non-zero size + live thumbnail) is observed; a verdict of
     * death requires the 404 to be confirmed twice, so a transient API blip
     * never poisons a healthy link. The verdict is cached per file.
     */
    protected function streamtapeLinkAvailable(\App\Models\StreamtapeLink $row): bool
    {
        $linkId = basename((string) parse_url((string) $row->streamtape_url, PHP_URL_PATH));
        if ($linkId === '') {
            return false;
        }

        return (bool) \Illuminate\Support\Facades\Cache::remember(
            'streamtape.alive.'.$linkId,
            600,
            function () use ($row, $linkId): bool {
                try {
                    $service = app(\App\Services\Streamtape\StreamtapeService::class);
                    $info = $service->fileInfo($linkId);
                } catch (\Throwable) {
                    return false;
                }
                $status = (int) ($info['status'] ?? 0);
                $thumb = (string) ($info['thumb'] ?? '');
                $size = (int) ($info['size'] ?? 0);

                if ($status === 404) {
                    // Transient API blips return 404 for healthy files; only
                    // a second consecutive 404 is treated as a real deletion.
                    usleep(1500000);
                    try {
                        $retry = $service->fileInfo($linkId);
                    } catch (\Throwable) {
                        return false;
                    }
                    if ((int) ($retry['status'] ?? 0) !== 404) {
                        $thumb = (string) ($retry['thumb'] ?? '');
                        $status = (int) ($retry['status'] ?? 0);
                        $size = (int) ($retry['size'] ?? 0);
                    }
                }
                if ($status === 404
                    || ($status === 200 && $size <= 0)
                    || ($status === 200 && $thumb === '')) {
                    if ($row->status === 'done') {
                        $row->update(['status' => 'failed', 'error' => 'Fichier purgé par Streamtape (lien mort)']);
                    }

                    return false;
                }
                if ($status !== 200) {
                    return false;
                }

                return $service->hasThumb($thumb);
            }
        );
    }

    protected function normalizeSources(array $files): array
    {
        $sources = [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            $url = $file['resourceLink'] ?? $file['url'] ?? null;
            if (! $url) {
                continue;
            }

            $resolution = (int) ($file['resolution'] ?? 0);
            $codec = $file['codecName'] ?? $file['codec'] ?? null;
            $source = [
                'url' => $url,
                'resolution' => $resolution,
                'quality' => $resolution > 0 ? $resolution.'p' : 'auto',
                'size' => isset($file['size']) ? (int) $file['size'] : null,
                'format' => $this->extFromUrl($url),
                'codec' => $codec,
                'durationSeconds' => isset($file['duration']) ? (int) $file['duration'] : null,
            ];
            // The media CDN rejects plain browser requests (it requires the
            // Android app UA — and the H5 download URLs additionally require
            // the videodownloader.site referer). Every CDN MP4 is therefore
            // served through the same-origin stream proxy, which attaches the
            // right upstream headers.
            if ($this->isCdnUrl($url)) {
                $source['url'] = $this->streamUrl($url);
            }
            // iOS-friendly playback URL for HEVC: routed through the remux proxy
            // that retags hev1 -> hvc1 so AVPlayer renders the picture.
            if ($this->isHevc($codec, $url) && is_string($url) && $this->remuxEnabled()) {
                $source['remux'] = $this->remuxUrl($url);
            }
            $sources[] = $source;
        }

        // Deduplicate by resolution, preferring H.264 over an unknown codec
        // over HEVC/H.265 for device compatibility (many phones only render
        // H.264 — HEVC often plays audio without video, or nothing on iOS via
        // a DASH fallback). The mobile resource files usually carry no codec
        // name at all, while the H5 downloads are explicit `h264`, so the H5
        // files win at equal resolution.
        $rank = function (?string $codec): int {
            if ($this->isHevc($codec)) {
                return 0;
            }

            return ($codec !== null && $codec !== '') ? 2 : 1;
        };
        $byKey = [];
        foreach ($sources as $source) {
            $key = $source['resolution'] ?: $source['url'];
            $existing = $byKey[$key] ?? null;
            if ($existing === null) {
                $byKey[$key] = $source;

                continue;
            }
            if ($rank($source['codec'] ?? null) > $rank($existing['codec'] ?? null)) {
                $byKey[$key] = $source;
            }
        }
        $out = array_values($byKey);
        // Highest resolution first, but H.264 ahead of HEVC at equal resolution.
        usort($out, function ($a, $b) use ($rank) {
            return [$b['resolution'], $rank($b['codec'] ?? null)]
                <=> [$a['resolution'], $rank($a['codec'] ?? null)];
        });

        return $out;
    }

    /** True if the codec name (or the file path) looks like HEVC / H.265. */
    protected function isHevc(?string $codec, ?string $url = null): bool
    {
        return (bool) (preg_match('/hevc|h\.?265/i', (string) $codec)
            || ($url !== null && preg_match('#/h265/|_h265_|/hevc/|\.hevc\.#i', $url)));
    }

    /** True if the URL points at the media CDN (needs proxying for browsers). */
    protected function isCdnUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        foreach ((array) config('moviebox.cdn_proxy_allow', []) as $suffix) {
            if ($suffix !== '' && str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the HEVC->hvc1 fix endpoint is available. */
    protected function remuxEnabled(): bool
    {
        return (bool) config('moviebox.hevc_fix', true) && function_exists('curl_init');
    }

    /**
     * Build a same-origin, HMAC-signed URL that streams the given HEVC file
     * remuxed to `hvc1` fragmented MP4 (so iOS renders the video). The
     * signature prevents the endpoint being abused as an open transcode relay.
     */
    protected function remuxUrl(string $url): string
    {
        $exp = time() + 6 * 3600;
        $sig = hash_hmac('sha256', $url.'|'.$exp, (string) config('app.key'));

        return url('/api/mv-hevc').'?'.http_build_query(['u' => $url, 'e' => $exp, 's' => $sig]);
    }

    /**
     * Same-origin proxy URL for a CDN MP4 (see streamMp4). The signature
     * prevents the endpoint being abused as an open relay.
     */
    protected function streamUrl(string $url): string
    {
        $exp = time() + 6 * 3600;
        $sig = hash_hmac('sha256', $url.'|'.$exp, (string) config('app.key'));

        return url('/api/mv-mp4').'?'.http_build_query(['u' => $url, 'e' => $exp, 's' => $sig]);
    }

    /**
     * Headers the CDN expects on the H5 download URLs (bcdnxw…): the same
     * videodownloader.site browser context used by the H5 API itself.
     *
     * @return array<string,string>
     */
    protected function h5CdnHeaders(): array
    {
        return [
            'Origin' => 'https://videodownloader.site',
            'Referer' => 'https://videodownloader.site/',
            'X-Client-Info' => json_encode(['timezone' => config('moviebox.timezone', 'Europe/Paris')]),
            'X-Request-Lang' => (string) config('moviebox.language', 'fr'),
        ];
    }

    /**
     * Stream a CDN MP4 to the browser through our origin. The CDN only serves
     * requests carrying the app headers a browser cannot send (the Android
     * app UA; the H5 URLs additionally require the videodownloader.site
     * referer), so the <video> element fetches this same-origin endpoint
     * instead. Range requests are passed through, so seeking still works.
     */
    public function streamMp4(Request $request): StreamedResponse
    {
        $url = (string) $request->query('u', '');
        $exp = (int) $request->query('e', 0);
        $sig = (string) $request->query('s', '');

        abort_if($url === '' || $exp <= 0 || $sig === '', 404);
        abort_if(time() > $exp, 410, 'Link expired — reload the page.');
        $expected = hash_hmac('sha256', $url.'|'.$exp, (string) config('app.key'));
        abort_unless(hash_equals($expected, $sig), 403, 'Invalid signature.');
        abort_unless($this->isCdnUrl($url), 403, 'Host not allowed.');

        $headers = ['User-Agent' => (string) config('moviebox.user_agent')];
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (str_contains($host, 'bcdnxw')) {
            $headers = array_merge($headers, $this->h5CdnHeaders());
        }

        $range = $request->header('Range');
        $upstream = Http::withOptions(['stream' => true])
            ->withHeaders(array_filter(array_merge($headers, ['Range' => $range])))
            ->timeout((int) config('moviebox.timeout', 30))
            ->get($url);

        abort_if($upstream->failed(), 502, 'Upstream media fetch failed.');

        $outHeaders = ['Access-Control-Allow-Origin' => '*'];
        $outHeaders['Content-Type'] = $upstream->header('Content-Type') ?: 'video/mp4';
        foreach (['Content-Range', 'Accept-Ranges', 'Content-Length'] as $h) {
            $val = $upstream->header($h);
            if ($val !== '') {
                $outHeaders[$h] = $val;
            }
        }
        $outHeaders['Cache-Control'] = 'public, max-age=3600';

        $body = $upstream->toPsrResponse()->getBody();

        return response()->stream(function () use ($body) {
            @set_time_limit(0);
            while (true) {
                try {
                    $chunk = $body->read(65536);
                } catch (\Throwable $e) {
                    break;
                }
                if ($chunk === '') {
                    break;
                }
                echo $chunk;
                if (connection_aborted()) {
                    break;
                }
            }
        }, $upstream->status(), $outHeaders);
    }

    /**
     * Proxy an HEVC MP4 and rewrite the sample-entry fourcc `hev1` -> `hvc1`
     * on the fly so Safari / iOS AVPlayer renders the picture (they play
     * `hev1` as audio-only). Pure PHP (cURL) — no ffmpeg, so it runs on shared
     * / cPanel hosting. Range requests are honoured, so seeking still works.
     *
     * The fourcc only differs in two bytes ('hev1' -> 'hvc1': e->v, v->c), so
     * the patch is applied byte-wise as the stream flows and is safe across
     * chunk / range boundaries.
     */
    public function remuxHevc(Request $request): StreamedResponse
    {
        $url = (string) $request->query('u', '');
        $exp = (int) $request->query('e', 0);
        $sig = (string) $request->query('s', '');

        abort_if($url === '' || $exp <= 0 || $sig === '', 404);
        abort_if(time() > $exp, 410, 'Link expired — reload the page.');
        $expected = hash_hmac('sha256', $url.'|'.$exp, (string) config('app.key'));
        abort_unless(hash_equals($expected, $sig), 403, 'Invalid signature.');

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $allowed = false;
        foreach ((array) config('moviebox.cdn_proxy_allow', []) as $suffix) {
            if ($suffix !== '' && str_ends_with($host, $suffix)) {
                $allowed = true;
                break;
            }
        }
        abort_unless($allowed, 403, 'Host not allowed.');
        abort_unless($this->remuxEnabled(), 501, 'HEVC fix is disabled on this server.');

        $info = $this->hevcPatchInfo($url);
        $total = (int) $info['total'];
        $offsets = $info['offsets'];
        abort_if($total <= 0, 502, 'Could not read the media file.');

        [$start, $end] = $this->parseRange($request->header('Range'), $total);
        $hasRange = $request->header('Range') !== null;
        $status = $hasRange ? 206 : 200;

        $headers = [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) ($end - $start + 1),
            'Cache-Control' => 'public, max-age=3600',
            'Access-Control-Allow-Origin' => '*',
        ];
        if ($hasRange) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$total}";
        }

        $ua = (string) config('moviebox.user_agent');

        return response()->stream(function () use ($url, $start, $end, $offsets, $ua) {
            @set_time_limit(0);
            $absPos = $start;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_BUFFERSIZE => 65536,
                CURLOPT_HTTPHEADER => [
                    'Range: bytes='.$start.'-'.$end,
                    'User-Agent: '.$ua,
                ],
                CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$absPos, $offsets) {
                    $len = strlen($data);
                    foreach ($offsets as $o) {
                        // 'hev1' -> 'hvc1': byte o+1 'e'->'v', byte o+2 'v'->'c'.
                        $p1 = $o + 1 - $absPos;
                        if ($p1 >= 0 && $p1 < $len) {
                            $data[$p1] = 'v';
                        }
                        $p2 = $o + 2 - $absPos;
                        if ($p2 >= 0 && $p2 < $len) {
                            $data[$p2] = 'c';
                        }
                    }
                    echo $data;
                    $absPos += $len;

                    return connection_aborted() ? 0 : $len;
                },
            ]);
            curl_exec($ch);
            curl_close($ch);
        }, $status, $headers);
    }

    /**
     * Locate the byte offsets of every `hev1` sample-entry fourcc inside the
     * file's `moov` box (walking the box tree with tiny range requests so it
     * works whether moov is at the front or the end). Cached per URL.
     *
     * @return array{total:int,offsets:array<int,int>}
     */
    protected function hevcPatchInfo(string $url): array
    {
        $cacheKey = 'hevcpatch:'.sha1($url);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $total = 0;
        $offsets = [];
        $pos = 0;

        for ($i = 0; $i < 64; $i++) {
            $head = $this->curlRange($url, $pos, $pos + 15, $total);
            if ($head === null || strlen($head) < 8) {
                break;
            }

            $size = unpack('N', substr($head, 0, 4))[1];
            $type = substr($head, 4, 4);
            $headerSize = 8;

            if ($size === 1) {
                if (strlen($head) < 16) {
                    break;
                }
                $hi = unpack('N', substr($head, 8, 4))[1];
                $lo = unpack('N', substr($head, 12, 4))[1];
                $size = $hi * 4294967296 + $lo;
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $total > 0 ? $total - $pos : 0;
            }

            if ($type === 'moov') {
                $moovLen = min($size, 8 * 1024 * 1024);
                $moov = $this->curlRange($url, $pos, $pos + $moovLen - 1, $total);
                if ($moov !== null) {
                    $off = 0;
                    while (($idx = strpos($moov, 'hev1', $off)) !== false) {
                        $offsets[] = $pos + $idx;
                        $off = $idx + 4;
                    }
                }
                break;
            }

            if ($size < $headerSize) {
                break;
            }
            $pos += $size;
            if ($total > 0 && $pos >= $total) {
                break;
            }
        }

        $result = ['total' => $total, 'offsets' => array_values(array_unique($offsets))];
        Cache::put($cacheKey, $result, 6 * 3600);

        return $result;
    }

    /**
     * Fetch a byte range via cURL. Captures the file's total size from the
     * Content-Range header into $total.
     */
    protected function curlRange(string $url, int $start, int $end, int &$total): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => (int) config('moviebox.timeout', 30),
            CURLOPT_HTTPHEADER => [
                'Range: bytes='.$start.'-'.$end,
                'User-Agent: '.(string) config('moviebox.user_agent'),
            ],
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$total) {
                if (stripos($line, 'Content-Range:') === 0 && preg_match('#/(\d+)#', $line, $m)) {
                    $total = (int) $m[1];
                }

                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            return null;
        }

        return $body;
    }

    /**
     * Parse a single HTTP Range header against the known total size.
     *
     * @return array{0:int,1:int} [start, end] (inclusive)
     */
    protected function parseRange(?string $header, int $total): array
    {
        $last = max(0, $total - 1);
        if ($header === null || ! preg_match('/bytes=(\d*)-(\d*)/', $header, $m)) {
            return [0, $last];
        }

        $start = $m[1] === '' ? null : (int) $m[1];
        $end = $m[2] === '' ? null : (int) $m[2];

        if ($start === null) {
            // Suffix range: the final N bytes.
            $len = $end ?? 0;
            $start = max(0, $total - $len);
            $end = $last;
        } else {
            if ($end === null || $end > $last) {
                $end = $last;
            }
        }

        if ($start > $end || $start < 0) {
            return [0, $last];
        }

        return [$start, $end];
    }

    /**
     * @param  array<int,mixed>  $captions
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeCaptions(array $captions): array
    {
        return array_values(array_map(fn ($caption) => [
            'lang' => $caption['lan'] ?? null,
            'label' => $caption['lanName'] ?? ($caption['lan'] ?? 'Subtitle'),
            'url' => $caption['url'] ?? null,
            'format' => $this->extFromUrl($caption['url'] ?? ''),
        ], array_filter($captions, fn ($c) => is_array($c) && ! empty($c['url']))));
    }

    /**
     * Proxy + normalise a subtitle file to WebVTT so it can be attached to the
     * HTML5 player without cross-origin issues.
     */
    public function subtitle(Request $request): Response
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $url = $validated['url'];
        $ext = $this->extFromUrl($url);
        abort_unless(in_array($ext, ['srt', 'vtt', null], true), 415, 'Unsupported subtitle format.');

        $response = Http::timeout((int) config('moviebox.timeout', 30))
            ->withHeaders(['User-Agent' => config('moviebox.user_agent')])
            ->get($url);

        abort_if($response->failed(), 502, 'Could not fetch subtitle.');

        $body = $response->body();
        $vtt = $ext === 'vtt' || str_starts_with(ltrim($body), 'WEBVTT')
            ? $body
            : $this->srtToVtt($body);

        return response($vtt, 200, [
            'Content-Type' => 'text/vtt; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    protected function srtToVtt(string $srt): string
    {
        $srt = preg_replace('/^\xEF\xBB\xBF/', '', $srt);
        $srt = str_replace(["\r\n", "\r"], "\n", $srt);
        $srt = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $srt);

        return "WEBVTT\n\n".trim($srt)."\n";
    }

    /**
     * @return array{subjectId:string,season:int,episode:int,title:string}
     */
    protected function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'subjectId' => ['required', 'string'],
            'detailPath' => ['sometimes', 'nullable', 'string'], // ignored (v3 uses subjectId)
            'season' => ['sometimes', 'integer', 'min:0'],
            'episode' => ['sometimes', 'integer', 'min:0'],
            'title' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        return [
            'subjectId' => $validated['subjectId'],
            'season' => (int) ($validated['season'] ?? 0),
            'episode' => (int) ($validated['episode'] ?? 0),
            'title' => trim((string) ($validated['title'] ?? '')),
        ];
    }

    protected function extFromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return $ext !== '' ? strtolower($ext) : null;
    }
}
