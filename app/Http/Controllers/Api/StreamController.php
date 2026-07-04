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
     * Resolve playable sources for a title. For movies pass se=0 & ep=0; for a
     * series episode pass the season & episode numbers. Subtitles are merged
     * from the download endpoint's caption list (best-effort).
     */
    public function play(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $debug = $request->boolean('debug') || config('app.debug');
        $diagnostics = [];

        $streams = [];
        $hls = [];
        $downloads = [];
        $subtitles = [];
        $hasResource = false;

        // The /download endpoint returns direct, browser-playable MP4 URLs and
        // is the most reliable source (it's what the upstream library uses).
        try {
            $download = $this->client->download(
                $validated['subjectId'],
                $validated['season'],
                $validated['episode'],
                $validated['detailPath'] ?? null
            );
            $downloads = $this->normalizeSources($download['downloads'] ?? [], resolutionKey: 'resolution');
            $subtitles = $this->normalizeCaptions($download['captions'] ?? []);
            $hasResource = $hasResource || (bool) ($download['hasResource'] ?? false);
            $diagnostics['download'] = [
                'ok' => true,
                'hasResource' => $download['hasResource'] ?? null,
                'downloadCount' => is_array($download['downloads'] ?? null) ? count($download['downloads']) : 0,
                'captionCount' => is_array($download['captions'] ?? null) ? count($download['captions']) : 0,
                'limited' => $download['limited'] ?? null,
                'limitedCode' => $download['limitedCode'] ?? null,
            ];
        } catch (\Throwable $e) {
            report($e);
            $diagnostics['download'] = ['ok' => false, 'type' => class_basename($e), 'error' => $e->getMessage()];
        }

        // The /play endpoint may add adaptive streams / HLS on top.
        try {
            $data = $this->client->play(
                $validated['subjectId'],
                $validated['season'],
                $validated['episode'],
                $validated['detailPath'] ?? null
            );
            $streams = $this->normalizeSources($data['streams'] ?? []);
            $hls = array_values(array_filter(array_map(
                fn ($h) => is_array($h) ? ($h['url'] ?? null) : (is_string($h) ? $h : null),
                $data['hls'] ?? []
            )));
            $hasResource = $hasResource || (bool) ($data['hasResource'] ?? false);
            $diagnostics['play'] = [
                'ok' => true,
                'hasResource' => $data['hasResource'] ?? null,
                'streamCount' => is_array($data['streams'] ?? null) ? count($data['streams']) : 0,
                'hlsCount' => is_array($data['hls'] ?? null) ? count($data['hls']) : 0,
            ];
        } catch (\Throwable $e) {
            report($e);
            $diagnostics['play'] = ['ok' => false, 'type' => class_basename($e), 'error' => $e->getMessage()];
        }

        // Prefer direct MP4 downloads, then merge in any extra resolutions the
        // play endpoint offered (deduplicated by resolution).
        $sources = $this->mergeSources($downloads, $streams);

        $payload = [
            'sources' => $sources,
            'hls' => $hls,
            'subtitles' => $subtitles,
            'hasResource' => $hasResource || $sources !== [] || $hls !== [],
        ];

        if ($debug) {
            $payload['debug'] = [
                'params' => $validated,
                'host' => $this->client->baseUrl(),
                'referer' => $validated['detailPath']
                    ? $this->client->baseUrl().'/movies/'.ltrim($validated['detailPath'], '/')
                    : null,
                'calls' => $diagnostics,
            ];
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Merge two source lists, de-duplicating by resolution (primary wins) and
     * ordering highest quality first.
     *
     * @param  array<int,array<string,mixed>>  $primary
     * @param  array<int,array<string,mixed>>  $secondary
     * @return array<int,array<string,mixed>>
     */
    protected function mergeSources(array $primary, array $secondary): array
    {
        $byKey = [];
        foreach ([...$primary, ...$secondary] as $source) {
            if (empty($source['url'])) {
                continue;
            }
            $key = ($source['resolution'] ?? 0) ?: $source['url'];
            $byKey[$key] ??= $source;
        }

        $merged = array_values($byKey);
        usort($merged, fn ($a, $b) => ($b['resolution'] ?? 0) <=> ($a['resolution'] ?? 0));

        return $merged;
    }

    /** Downloadable media files + subtitle files. */
    public function download(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        $data = $this->client->download(
            $validated['subjectId'],
            $validated['season'],
            $validated['episode'],
            $validated['detailPath'] ?? null
        );

        return response()->json([
            'data' => [
                'downloads' => $this->normalizeSources($data['downloads'] ?? [], resolutionKey: 'resolution'),
                'subtitles' => $this->normalizeCaptions($data['captions'] ?? []),
                'limited' => (bool) ($data['limited'] ?? false),
                'hasResource' => (bool) ($data['hasResource'] ?? true),
            ],
        ]);
    }

    /**
     * Proxy + normalise a subtitle file to WebVTT so it can be attached to the
     * HTML5 player without cross-origin issues (browsers require CORS + VTT for
     * <track>; many upstream captions are SRT served without CORS headers).
     */
    public function subtitle(Request $request): Response
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $url = $validated['url'];

        // Only allow subtitle-ish files to be proxied.
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

    /** Convert SubRip (SRT) content to WebVTT. */
    protected function srtToVtt(string $srt): string
    {
        $srt = preg_replace('/^\xEF\xBB\xBF/', '', $srt); // strip BOM
        $srt = str_replace(["\r\n", "\r"], "\n", $srt);

        // SRT uses comma millisecond separators; VTT uses dots.
        $srt = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $srt);

        return "WEBVTT\n\n".trim($srt)."\n";
    }

    /**
     * @return array{subjectId:string,season:int,episode:int,detailPath:?string}
     */
    protected function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'subjectId' => ['required', 'string'],
            'detailPath' => ['sometimes', 'nullable', 'string'],
            'season' => ['sometimes', 'integer', 'min:0'],
            'episode' => ['sometimes', 'integer', 'min:0'],
        ]);

        return [
            'subjectId' => $validated['subjectId'],
            'detailPath' => $validated['detailPath'] ?? null,
            'season' => (int) ($validated['season'] ?? 0),
            'episode' => (int) ($validated['episode'] ?? 0),
        ];
    }

    /**
     * @param  array<int,mixed>  $streams
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeSources(array $streams, string $resolutionKey = 'resolutions'): array
    {
        $sources = array_values(array_map(function ($stream) use ($resolutionKey) {
            $resolution = (int) ($stream[$resolutionKey] ?? $stream['resolution'] ?? $stream['resolutions'] ?? 0);

            return [
                'url' => $stream['url'] ?? null,
                'resolution' => $resolution,
                'quality' => $resolution > 0 ? $resolution.'p' : 'auto',
                'size' => isset($stream['size']) ? (int) $stream['size'] : null,
                'format' => $stream['format'] ?? $this->extFromUrl($stream['url'] ?? ''),
                'codec' => $stream['codecName'] ?? null,
                'durationSeconds' => isset($stream['duration']) ? (int) $stream['duration'] : null,
            ];
        }, array_filter($streams, fn ($s) => is_array($s) && ! empty($s['url']))));

        usort($sources, fn ($a, $b) => $b['resolution'] <=> $a['resolution']);

        return $sources;
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
