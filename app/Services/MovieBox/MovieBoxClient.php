<?php

namespace App\Services\MovieBox;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Native PHP client for the MovieBox (aoneroom) "h5 BFF" backend.
 *
 * This mirrors the HTTP behaviour of the upstream Python project
 * `Simatwa/moviebox-api`: it bootstraps a bearer token + cookies, then calls
 * the same public endpoints for search, discovery, streaming and downloads.
 * All backend responses are wrapped as {code, message, data}; this client
 * unwraps and returns the `data` payload.
 */
class MovieBoxClient
{
    protected string $host;

    protected string $apiHost;

    protected string $scheme;

    protected int $timeout;

    protected ?string $proxy;

    protected string $userAgent;

    protected int $cacheTtl;

    protected int $tokenTtl;

    protected CookieJar $cookieJar;

    protected ?string $token = null;

    protected bool $bootstrapped = false;

    protected const TOKEN_CACHE_KEY = 'moviebox:token';

    /**
     * @param  array<string,mixed>  $config  Optional overrides for config/moviebox.php
     */
    public function __construct(array $config = [])
    {
        $config = array_merge(config('moviebox'), $config);

        $this->host = $config['host'];
        $this->apiHost = $config['api_host'];
        $this->scheme = $config['scheme'];
        $this->timeout = (int) $config['timeout'];
        $this->proxy = $config['proxy'] ?? null;
        $this->userAgent = $config['user_agent'];
        $this->cacheTtl = (int) $config['cache_ttl'];
        $this->tokenTtl = (int) $config['token_ttl'];

        $this->cookieJar = new CookieJar;
    }

    public function baseUrl(): string
    {
        return "{$this->scheme}://{$this->host}";
    }

    public function apiBaseUrl(): string
    {
        return "{$this->scheme}://{$this->apiHost}";
    }

    // -----------------------------------------------------------------
    // Discovery & catalog
    // -----------------------------------------------------------------

    /** Landing-page content (banners, curated lists). */
    public function home(): array
    {
        return $this->cached('home', fn () => $this->getData('/wefeed-h5-bff/web/home', context: 'home'));
    }

    /** Trending movies / TV series. Page is zero-indexed upstream. */
    public function trending(int $page = 0, int $perPage = 18): array
    {
        return $this->cached("trending:$page:$perPage", fn () => $this->getData(
            '/wefeed-h5-bff/web/subject/trending',
            ['page' => $page, 'perPage' => $perPage],
            context: 'trending'
        ));
    }

    /** Full search. $subjectType 0=all, 1=movies, 2=tv-series. */
    public function search(string $keyword, int $subjectType = 0, int $page = 1, int $perPage = 24): array
    {
        $key = 'search:'.md5("$keyword|$subjectType|$page|$perPage");

        return $this->cached($key, function () use ($keyword, $subjectType, $page, $perPage) {
            return $this->postData(
                $this->apiBaseUrl().'/wefeed-h5api-bff/subject/search',
                [
                    'keyword' => $keyword,
                    'page' => $page,
                    'perPage' => $perPage,
                    'subjectType' => $subjectType,
                ],
                context: 'search'
            );
        });
    }

    /** Lightweight title suggestions for an autocomplete box. */
    public function suggest(string $keyword, int $perPage = 10): array
    {
        return $this->postData(
            $this->baseUrl().'/wefeed-h5-bff/web/subject/search-suggest',
            ['keyword' => $keyword, 'per_page' => $perPage],
            context: 'suggest'
        );
    }

    /** Titles many people are searching for right now. */
    public function popularSearch(): array
    {
        $data = $this->cached('popular', fn () => $this->getData(
            '/wefeed-h5-bff/web/subject/everyone-search',
            context: 'popular-search'
        ));

        return $data['everyoneSearch'] ?? [];
    }

    /** Editorial "hot" movies + tv lists. */
    public function hot(): array
    {
        return $this->cached('hot', fn () => $this->getData(
            '/wefeed-h5-bff/web/subject/search-rank',
            context: 'hot'
        ));
    }

    /** "More like this" recommendations for a subject. */
    public function recommend(string $subjectId, int $page = 1, int $perPage = 24): array
    {
        $key = "recommend:$subjectId:$page:$perPage";

        return $this->cached($key, fn () => $this->getData(
            '/wefeed-h5-bff/web/subject/detail-rec',
            ['subjectId' => $subjectId, 'page' => $page, 'perPage' => $perPage],
            context: 'recommend'
        ));
    }

    /**
     * Best-effort rich details (seasons/episodes, cast, reviews) parsed from
     * the detail HTML page. Returns null if the page can't be parsed.
     */
    public function detail(string $detailPath, string $subjectId): ?array
    {
        $key = "detail:$subjectId:".md5($detailPath);

        return $this->cached($key, function () use ($detailPath, $subjectId) {
            $this->ensureBootstrapped();

            $url = $this->baseUrl().'/detail/'.ltrim($detailPath, '/').'?id='.$subjectId;

            try {
                $response = $this->client()->get($url);
            } catch (Throwable $e) {
                report($e);

                return null;
            }

            if ($response->failed()) {
                return null;
            }

            return DetailExtractor::extract($response->body());
        });
    }

    // -----------------------------------------------------------------
    // Streaming & downloads
    // -----------------------------------------------------------------

    /**
     * Playable stream sources for a movie (se=0, ep=0) or a series episode.
     *
     * @return array{streams?:array,hls?:array,dash?:array,hasResource?:bool}
     */
    public function play(string $subjectId, int $season = 0, int $episode = 0, ?string $detailPath = null): array
    {
        return $this->mediaEndpoint('/wefeed-h5-bff/web/subject/play', $subjectId, $season, $episode, $detailPath, 'play');
    }

    /**
     * Downloadable media files + subtitle (caption) files.
     *
     * @return array{downloads?:array,captions?:array,hasResource?:bool}
     */
    public function download(string $subjectId, int $season = 0, int $episode = 0, ?string $detailPath = null): array
    {
        return $this->mediaEndpoint('/wefeed-h5-bff/web/subject/download', $subjectId, $season, $episode, $detailPath, 'download');
    }

    protected function mediaEndpoint(string $path, string $subjectId, int $season, int $episode, ?string $detailPath, string $context): array
    {
        $this->ensureBootstrapped();

        $headers = [];
        if ($detailPath) {
            // Without a matching Referer the backend serves an empty body.
            $headers['Referer'] = $this->baseUrl().'/movies/'.ltrim($detailPath, '/');
        }

        return $this->unwrap(
            $this->client($headers)->get($this->baseUrl().$path, [
                'subjectId' => $subjectId,
                'se' => $season,
                'ep' => $episode,
            ]),
            $context
        );
    }

    // -----------------------------------------------------------------
    // Bootstrap (token + cookies)
    // -----------------------------------------------------------------

    public function ensureBootstrapped(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->token = $this->resolveToken();
        $this->fetchAppCookies();
        $this->bootstrapped = true;
    }

    protected function resolveToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, $this->tokenTtl, fn () => $this->fetchToken());
    }

    /**
     * The search-suggest endpoint attaches an auth bearer token to the
     * response via the `x-user` header. We reuse it for later requests.
     */
    protected function fetchToken(): string
    {
        try {
            $response = $this->client(withAuth: false)->post(
                $this->apiBaseUrl().'/wefeed-h5api-bff/subject/search-suggest',
                ['keyword' => 'avatar', 'perPage' => 0]
            );
        } catch (ConnectionException $e) {
            throw MovieBoxException::upstream('Could not reach MovieBox to bootstrap a token: '.$e->getMessage());
        }

        $xUser = $response->header('x-user');

        if (! $xUser) {
            throw MovieBoxException::upstream('Token bootstrap failed: response is missing the x-user header.');
        }

        $decoded = json_decode($xUser, true);

        if (! is_array($decoded) || empty($decoded['token'])) {
            throw MovieBoxException::upstream('Token bootstrap failed: no token present in x-user header.');
        }

        return $decoded['token'];
    }

    /**
     * Fetch the latest app package info purely to obtain the `account`/`token`
     * cookies the backend expects on subsequent requests. Best-effort.
     */
    protected function fetchAppCookies(): void
    {
        try {
            $this->client()->get(
                $this->baseUrl().'/wefeed-h5-bff/app/get-latest-app-pkgs',
                ['app_name' => 'moviebox']
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    // -----------------------------------------------------------------
    // Low-level helpers
    // -----------------------------------------------------------------

    protected function client(array $headers = [], bool $withAuth = true): PendingRequest
    {
        $defaults = [
            'Accept' => 'application/json',
            'Accept-Language' => 'en-US,en;q=0.9',
            'User-Agent' => $this->userAgent,
            'X-Client-Info' => json_encode(['timezone' => config('app.timezone', 'UTC')]),
            'Referer' => $this->baseUrl().'/',
            'Origin' => $this->baseUrl(),
        ];

        if ($withAuth && $this->token) {
            $defaults['Authorization'] = 'Bearer '.$this->token;
        }

        $options = ['cookies' => $this->cookieJar];
        if ($this->proxy) {
            $options['proxy'] = $this->proxy;
        }

        return Http::withHeaders(array_merge($defaults, $headers))
            ->withOptions($options)
            ->timeout($this->timeout)
            ->acceptJson();
    }

    protected function getData(string $path, array $query = [], string $context = 'request'): mixed
    {
        $this->ensureBootstrapped();

        return $this->unwrap($this->client()->get($this->baseUrl().$path, $query), $context);
    }

    protected function postData(string $url, array $payload, string $context = 'request'): mixed
    {
        $this->ensureBootstrapped();

        return $this->unwrap($this->client()->post($url, $payload), $context);
    }

    protected function unwrap(Response $response, string $context): mixed
    {
        if ($response->failed()) {
            throw MovieBoxException::upstream("MovieBox '$context' request failed with HTTP {$response->status()}.");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw MovieBoxException::upstream("MovieBox '$context' returned a non-JSON body.");
        }

        if (($json['code'] ?? 1) === 0 && ($json['message'] ?? null) === 'ok') {
            return $json['data'] ?? [];
        }

        $message = $json['message'] ?? 'unknown error';

        throw MovieBoxException::upstream("MovieBox '$context' error: $message");
    }

    protected function cached(string $key, callable $callback): mixed
    {
        if ($this->cacheTtl <= 0) {
            return $callback();
        }

        return Cache::remember("moviebox:$key", $this->cacheTtl, $callback);
    }
}
