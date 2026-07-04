<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MovieBox\MovieBoxClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
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
        $attempts = [];

        foreach ($this->mirrorClients() as $name => $client) {
            $result = $this->collectFromClient($client, $validated);
            $attempts[$name] = $result['diag'];

            if ($result['sources'] !== [] || $result['hls'] !== []) {
                $this->rememberWorkingMirror($name);

                $payload = [
                    'sources' => $result['sources'],
                    'hls' => $result['hls'],
                    'subtitles' => $result['subtitles'],
                    'hasResource' => true,
                    'mirror' => $name,
                ];
                if ($debug) {
                    $payload['debug'] = ['params' => $validated, 'attempts' => $attempts];
                }

                return response()->json(['data' => $payload]);
            }
        }

        $payload = ['sources' => [], 'hls' => [], 'subtitles' => [], 'hasResource' => false];
        if ($debug) {
            $payload['debug'] = ['params' => $validated, 'attempts' => $attempts];
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Build the ordered list of clients to try: the last-known working mirror
     * first (if any), then the primary host, then configured fallback mirrors.
     *
     * @return array<string,MovieBoxClient>
     */
    protected function mirrorClients(): array
    {
        $clients = ['primary' => $this->client];

        foreach ((array) config('moviebox.mirrors', []) as $mirror) {
            if (! is_array($mirror) || empty($mirror['host'])) {
                continue;
            }
            $clients[$mirror['host']] ??= new MovieBoxClient($mirror);
        }

        $working = Cache::get('moviebox:working_mirror');
        if ($working && isset($clients[$working])) {
            $clients = [$working => $clients[$working]] + $clients;
        }

        return $clients;
    }

    protected function rememberWorkingMirror(string $name): void
    {
        Cache::put('moviebox:working_mirror', $name, now()->addHours(6));
    }

    /**
     * Gather playable sources + subtitles from a single mirror (resilient).
     *
     * @param  array{subjectId:string,season:int,episode:int,detailPath:?string}  $v
     * @return array{sources:array,hls:array,subtitles:array,diag:array}
     */
    protected function collectFromClient(MovieBoxClient $client, array $v): array
    {
        $downloads = $streams = $hls = $subtitles = [];
        $diag = [];

        // Direct MP4 files (most reliable, browser-playable).
        try {
            $d = $client->download($v['subjectId'], $v['season'], $v['episode'], $v['detailPath'] ?? null);
            $downloads = $this->normalizeSources($d['downloads'] ?? [], resolutionKey: 'resolution');
            $subtitles = $this->normalizeCaptions($d['captions'] ?? []);
            $diag['download'] = [
                'ok' => true,
                'hasResource' => $d['hasResource'] ?? null,
                'downloadCount' => is_array($d['downloads'] ?? null) ? count($d['downloads']) : 0,
                'captionCount' => is_array($d['captions'] ?? null) ? count($d['captions']) : 0,
            ];
        } catch (\Throwable $e) {
            report($e);
            $diag['download'] = ['ok' => false, 'type' => class_basename($e), 'error' => $e->getMessage()];
        }

        // Adaptive streams / HLS on top.
        try {
            $p = $client->play($v['subjectId'], $v['season'], $v['episode'], $v['detailPath'] ?? null);
            $streams = $this->normalizeSources($p['streams'] ?? []);
            $hls = array_values(array_filter(array_map(
                fn ($h) => is_array($h) ? ($h['url'] ?? null) : (is_string($h) ? $h : null),
                $p['hls'] ?? []
            )));
            $diag['play'] = [
                'ok' => true,
                'hasResource' => $p['hasResource'] ?? null,
                'streamCount' => is_array($p['streams'] ?? null) ? count($p['streams']) : 0,
                'hlsCount' => is_array($p['hls'] ?? null) ? count($p['hls']) : 0,
            ];
        } catch (\Throwable $e) {
            report($e);
            $diag['play'] = ['ok' => false, 'type' => class_basename($e), 'error' => $e->getMessage()];
        }

        return [
            'sources' => $this->mergeSources($downloads, $streams),
            'hls' => $hls,
            'subtitles' => $subtitles,
            'diag' => $diag,
        ];
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

    /** Downloadable media files + subtitle files (with mirror fallback). */
    public function download(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        foreach ($this->mirrorClients() as $name => $client) {
            try {
                $data = $client->download(
                    $validated['subjectId'],
                    $validated['season'],
                    $validated['episode'],
                    $validated['detailPath'] ?? null
                );
            } catch (\Throwable $e) {
                report($e);

                continue;
            }

            $downloads = $this->normalizeSources($data['downloads'] ?? [], resolutionKey: 'resolution');
            $subtitles = $this->normalizeCaptions($data['captions'] ?? []);

            if ($downloads !== [] || $subtitles !== []) {
                $this->rememberWorkingMirror($name);

                return response()->json([
                    'data' => [
                        'downloads' => $downloads,
                        'subtitles' => $subtitles,
                        'limited' => (bool) ($data['limited'] ?? false),
                        'hasResource' => true,
                        'mirror' => $name,
                    ],
                ]);
            }
        }

        return response()->json([
            'data' => ['downloads' => [], 'subtitles' => [], 'limited' => false, 'hasResource' => false],
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
