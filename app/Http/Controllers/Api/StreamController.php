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

        $data = $this->client->play(
            $validated['subjectId'],
            $validated['season'],
            $validated['episode'],
            $validated['detailPath'] ?? null
        );

        $sources = $this->normalizeSources($data['streams'] ?? []);

        $hls = array_values(array_filter(array_map(
            fn ($h) => is_array($h) ? ($h['url'] ?? null) : (is_string($h) ? $h : null),
            $data['hls'] ?? []
        )));

        // Try to enrich with subtitles from the download endpoint.
        $subtitles = [];
        try {
            $download = $this->client->download(
                $validated['subjectId'],
                $validated['season'],
                $validated['episode'],
                $validated['detailPath'] ?? null
            );
            $subtitles = $this->normalizeCaptions($download['captions'] ?? []);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'data' => [
                'sources' => $sources,
                'hls' => $hls,
                'subtitles' => $subtitles,
                'hasResource' => (bool) ($data['hasResource'] ?? ($sources !== [] || $hls !== [])),
            ],
        ]);
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
