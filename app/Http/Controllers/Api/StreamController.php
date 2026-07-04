<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MovieBox\MovieBoxClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class StreamController extends Controller
{
    public function __construct(protected MovieBoxClient $client) {}

    /**
     * Resolve playable sources for a title. For movies pass season=0 & episode=0;
     * for a series episode pass the season & episode numbers.
     */
    public function play(Request $request): JsonResponse
    {
        $v = $this->validatePayload($request);
        $debug = $request->boolean('debug') || config('app.debug');
        $diag = [];

        $files = $this->resolveVideoFiles($v['subjectId'], $v['season'], $v['episode'], $diag);
        $sources = $this->normalizeSources($files);
        $subtitles = $this->resolveSubtitles($v['subjectId'], $files, $diag);

        $payload = [
            'sources' => $sources,
            'hls' => [],
            'subtitles' => $subtitles,
            'hasResource' => $sources !== [],
        ];

        if ($debug) {
            $payload['debug'] = [
                'params' => $v,
                'host' => $this->client->activeHost(),
                'fileCount' => count($files),
                'calls' => $diag,
            ];
        }

        return response()->json(['data' => $payload]);
    }

    /** Downloadable media files + subtitle files. */
    public function download(Request $request): JsonResponse
    {
        $v = $this->validatePayload($request);
        $diag = [];

        $files = $this->resolveVideoFiles($v['subjectId'], $v['season'], $v['episode'], $diag);

        return response()->json([
            'data' => [
                'downloads' => $this->normalizeSources($files),
                'subtitles' => $this->resolveSubtitles($v['subjectId'], $files, $diag),
                'hasResource' => $files !== [],
            ],
        ]);
    }

    /**
     * Fetch the resource list and return the video files matching the requested
     * season/episode (all resolution variants for a movie).
     *
     * @param  array<string,mixed>  $diag
     * @return array<int,array<string,mixed>>
     */
    protected function resolveVideoFiles(string $subjectId, int $season, int $episode, array &$diag): array
    {
        $isMovie = $season === 0 && $episode === 0;
        // `resource` returns a flat list of every episode across all seasons,
        // 20 per page (the API caps perPage at 20 — larger values are rejected).
        // Scan enough pages to reach later seasons, otherwise their episodes
        // fall past the pagination window and surface as "no stream available".
        $maxPages = $isMovie ? 1 : 40;
        $matched = [];
        $page = 1;

        do {
            try {
                $res = $this->client->resource($subjectId, 1080, $page, 20);
            } catch (\Throwable $e) {
                report($e);
                $diag["resource_page_$page"] = ['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()];
                break;
            }

            $list = is_array($res['list'] ?? null) ? $res['list'] : [];
            $diag["resource_page_$page"] = [
                'ok' => true,
                'listCount' => count($list),
                'hasMore' => $res['pager']['hasMore'] ?? false,
            ];

            if ($isMovie) {
                $matched = array_values(array_filter(
                    $list,
                    fn ($it) => is_array($it) && ! empty($it['resourceLink'])
                ));
                break;
            }

            foreach ($list as $it) {
                if (is_array($it)
                    && (int) ($it['se'] ?? -1) === $season
                    && (int) ($it['ep'] ?? -1) === $episode
                    && ! empty($it['resourceLink'])) {
                    $matched[] = $it;
                }
            }

            $hasMore = (bool) ($res['pager']['hasMore'] ?? false);
            $page++;
        } while ($matched === [] && $hasMore && $page <= $maxPages);

        return $matched;
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
            $sources[] = [
                'url' => $url,
                'resolution' => $resolution,
                'quality' => $resolution > 0 ? $resolution.'p' : 'auto',
                'size' => isset($file['size']) ? (int) $file['size'] : null,
                'format' => $this->extFromUrl($url),
                'codec' => $file['codecName'] ?? null,
                'durationSeconds' => isset($file['duration']) ? (int) $file['duration'] : null,
            ];
        }

        // Deduplicate by resolution (keep first) and order highest quality first.
        $byKey = [];
        foreach ($sources as $source) {
            $key = $source['resolution'] ?: $source['url'];
            $byKey[$key] ??= $source;
        }
        $out = array_values($byKey);
        usort($out, fn ($a, $b) => $b['resolution'] <=> $a['resolution']);

        return $out;
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
     * @return array{subjectId:string,season:int,episode:int}
     */
    protected function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'subjectId' => ['required', 'string'],
            'detailPath' => ['sometimes', 'nullable', 'string'], // ignored (v3 uses subjectId)
            'season' => ['sometimes', 'integer', 'min:0'],
            'episode' => ['sometimes', 'integer', 'min:0'],
        ]);

        return [
            'subjectId' => $validated['subjectId'],
            'season' => (int) ($validated['season'] ?? 0),
            'episode' => (int) ($validated['episode'] ?? 0),
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
