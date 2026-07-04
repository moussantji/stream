<?php

namespace App\Services\MovieBox;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Native PHP client for the MovieBox "wefeed-mobile-bff" API.
 *
 * Mirrors the upstream `Simatwa/moviebox-api` v3 client: every request is
 * HMAC-signed, a bearer token is bootstrapped from the tab-operating endpoint
 * (returned in the `x-user` response header), and requests are load-balanced
 * across a pool of API hosts with automatic failover on retryable statuses.
 */
class MovieBoxClient
{
    /** @var list<string> */
    protected array $hostPool;

    protected string $activeBase;

    protected int $timeout;

    protected ?string $proxy;

    protected string $userAgent;

    protected string $clientInfo;

    protected string $language;

    protected string $secretKey;

    protected int $tokenTtl;

    protected int $cacheTtl;

    protected ?string $token = null;

    protected bool $bootstrapped = false;

    protected const RETRY_STATUS = [403, 407, 429, 500, 502, 503, 504];

    protected const TOKEN_CACHE_KEY = 'moviebox:v3:token';

    // Endpoint paths --------------------------------------------------
    protected const MAIN_PAGE = '/wefeed-mobile-bff/tab-operating';

    protected const SEARCH = '/wefeed-mobile-bff/subject-api/search';

    protected const SUBJECT_GET = '/wefeed-mobile-bff/subject-api/get';

    protected const SEASON_INFO = '/wefeed-mobile-bff/subject-api/season-info';

    protected const RESOURCE = '/wefeed-mobile-bff/subject-api/resource';

    protected const PLAY_INFO = '/wefeed-mobile-bff/subject-api/play-info';

    protected const EXT_CAPTIONS = '/wefeed-mobile-bff/subject-api/get-ext-captions';

    /**
     * @param  array<string,mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $config = array_merge(config('moviebox'), $config);

        $this->hostPool = $config['host_pool'];
        $this->activeBase = $this->hostPool[0] ?? 'https://api6.aoneroom.com';
        $this->timeout = (int) $config['timeout'];
        $this->proxy = $config['proxy'] ?? null;
        $this->userAgent = $config['user_agent'];
        $this->clientInfo = $config['client_info'];
        $this->language = $config['language'] ?? 'en';
        $this->secretKey = $config['secret_key'];
        $this->tokenTtl = (int) $config['token_ttl'];
        $this->cacheTtl = (int) $config['cache_ttl'];
    }

    // -----------------------------------------------------------------
    // Catalog & discovery
    // -----------------------------------------------------------------

    /** Landing-page content for a tab (0=all, 2=movies, 5=tv). */
    public function home(int $tabId = 0, int $page = 1): array
    {
        return $this->cached("home:$tabId:$page", fn () => $this->getData(
            self::MAIN_PAGE,
            ['page' => $page, 'tabId' => $tabId, 'version' => ''],
            context: 'home'
        ));
    }

    /** Full search. $subjectType 0=all, 1=movies, 2=tv-series. */
    public function search(string $keyword, int $subjectType = 0, int $page = 1, int $perPage = 20): array
    {
        $perPage = max(1, min(20, $perPage));
        $key = 'search:'.md5("$keyword|$subjectType|$page|$perPage");

        return $this->cached($key, fn () => $this->postData(
            self::SEARCH,
            ['keyword' => $keyword, 'page' => $page, 'perPage' => $perPage, 'subjectType' => $subjectType],
            context: 'search'
        ));
    }

    /** Full item metadata (cast, dubs, resource detectors …). */
    public function itemDetails(string $subjectId): array
    {
        return $this->cached("detail:$subjectId", fn () => $this->getData(
            self::SUBJECT_GET,
            ['subjectId' => $subjectId],
            context: 'detail'
        ));
    }

    /** Season / episode counts for a series. */
    public function seasonInfo(string $subjectId): array
    {
        return $this->cached("seasons:$subjectId", fn () => $this->getData(
            self::SEASON_INFO,
            ['subjectId' => $subjectId],
            context: 'season-info'
        ));
    }

    // -----------------------------------------------------------------
    // Streaming & downloads
    // -----------------------------------------------------------------

    /**
     * Downloadable / streamable video files (direct MP4 URLs, all resolutions,
     * paginated across episodes for series).
     */
    public function resource(string $subjectId, int $resolution = 1080, int $page = 1, int $perPage = 20): array
    {
        return $this->getData(
            self::RESOURCE,
            ['subjectId' => $subjectId, 'resolution' => $resolution, 'page' => $page, 'perPage' => $perPage],
            context: 'resource'
        );
    }

    /** External subtitle files for a specific resource (video file). */
    public function extCaptions(string $subjectId, string $resourceId): array
    {
        return $this->getData(
            self::EXT_CAPTIONS,
            ['subjectId' => $subjectId, 'resourceId' => $resourceId],
            context: 'ext-captions'
        );
    }

    /** Adaptive (DASH/MPD) play info for a movie/episode. */
    public function playInfo(string $subjectId, int $season = 0, int $episode = 0): array
    {
        return $this->getData(
            self::PLAY_INFO,
            ['subjectId' => $subjectId, 'se' => $season, 'ep' => $episode],
            context: 'play-info',
            playMode: true
        );
    }

    // -----------------------------------------------------------------
    // Diagnostics
    // -----------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    public function probe(): array
    {
        $report = ['hostPool' => $this->hostPool, 'proxy' => $this->proxy ? 'configured' : 'none'];

        try {
            $this->bootstrapped = false;
            $this->token = null;
            $data = $this->getData(self::MAIN_PAGE, ['page' => 1, 'tabId' => 0, 'version' => ''], context: 'probe');
            $report['activeHost'] = $this->activeBase;
            $report['tokenBootstrap'] = $this->token ? 'ok ('.strlen($this->token).' chars)' : 'no token in x-user';
            $report['home'] = ['ok' => true, 'sections' => is_array($data['items'] ?? null) ? count($data['items']) : 0];
        } catch (Throwable $e) {
            $report['tokenBootstrap'] = 'FAILED';
            $report['error'] = class_basename($e).': '.$e->getMessage();
            $report['activeHost'] = $this->activeBase;
        }

        return $report;
    }

    public function activeHost(): string
    {
        return $this->activeBase;
    }

    // -----------------------------------------------------------------
    // Transport
    // -----------------------------------------------------------------

    public function ensureBootstrapped(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            $this->token = $cached;
            $this->bootstrapped = true;

            return;
        }

        // First call has no auth token; the server issues one via x-user.
        $this->request('GET', self::MAIN_PAGE, ['page' => 1, 'tabId' => 0, 'version' => '']);

        if (! $this->token) {
            throw MovieBoxException::upstream('Token bootstrap failed: no token in x-user header.');
        }

        $this->bootstrapped = true;
    }

    protected function getData(string $path, array $params = [], string $context = 'request', bool $playMode = false): mixed
    {
        if ($context !== 'probe') {
            $this->ensureBootstrapped();
        }

        [, $response] = $this->request('GET', $path, $params, playMode: $playMode);

        return $this->unwrap($response, $context);
    }

    protected function postData(string $path, array $payload, string $context = 'request'): mixed
    {
        $this->ensureBootstrapped();

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        [, $response] = $this->request('POST', $path, [], $body);

        return $this->unwrap($response, $context);
    }

    /**
     * Perform a signed request, iterating the host pool until a non-retryable
     * response is received.
     *
     * @return array{0:string,1:Response}
     */
    protected function request(string $method, string $path, array $params = [], ?string $body = null, bool $playMode = false): array
    {
        $query = $params === [] ? '' : http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $pathWithQuery = $query === '' ? $path : $path.'?'.$query;

        $lastResponse = null;

        foreach ($this->hostPool as $base) {
            try {
                $client = $this->buildClient($method, $path, $params, $body, $playMode);
                $response = $method === 'GET'
                    ? $client->get($base.$pathWithQuery)
                    : $client->withBody($body ?? '', 'application/json; charset=utf-8')->post($base.$pathWithQuery);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $this->absorbToken($response);
            $lastResponse = $response;

            if (! in_array($response->status(), self::RETRY_STATUS, true)) {
                $this->activeBase = $base;

                return [$base, $response];
            }
        }

        if ($lastResponse === null) {
            throw MovieBoxException::upstream("All MovieBox hosts were unreachable for '$path'.");
        }

        return [$this->activeBase, $lastResponse];
    }

    protected function buildClient(string $method, string $path, array $params, ?string $body, bool $playMode): PendingRequest
    {
        $ts = (int) round(microtime(true) * 1000);
        $accept = 'application/json';
        $contentType = $method === 'GET' ? 'application/json' : 'application/json; charset=utf-8';

        $headers = [
            'User-Agent' => $this->userAgent,
            'Accept' => $accept,
            'Accept-Language' => $this->language.'-'.strtoupper($this->language).','.$this->language.';q=0.9',
            'Content-Type' => $contentType,
            'Connection' => 'keep-alive',
            'X-Client-Token' => Signer::clientToken($ts),
            'x-tr-signature' => Signer::signature($method, $accept, $contentType, $path, $params, $body, $this->secretKey, $ts),
            'X-Client-Info' => $this->clientInfo,
            'X-Client-Status' => '0',
        ];

        if ($this->token) {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        if ($playMode) {
            $headers['X-Play-Mode'] = '2';
        }

        $request = Http::withHeaders($headers)
            ->connectTimeout(min(10, $this->timeout))
            ->timeout($this->timeout);

        if ($this->proxy) {
            $request->withOptions(['proxy' => $this->proxy]);
        }

        return $request;
    }

    /** Absorb a fresh bearer token from the x-user response header. */
    protected function absorbToken(Response $response): void
    {
        $xUser = $response->header('x-user');
        if (! $xUser) {
            return;
        }

        $decoded = json_decode($xUser, true);
        if (is_array($decoded) && ! empty($decoded['token'])) {
            $this->token = $decoded['token'];
            Cache::put(self::TOKEN_CACHE_KEY, $this->token, $this->tokenTtl);
        }
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

        $code = $json['code'] ?? 0;
        if ($code !== 0 && $code !== '0') {
            $message = $json['message'] ?? 'unknown error';
            throw MovieBoxException::upstream("MovieBox '$context' error (code $code): $message");
        }

        return $json['data'] ?? $json;
    }

    protected function cached(string $key, callable $callback): mixed
    {
        if ($this->cacheTtl <= 0) {
            return $callback();
        }

        return Cache::remember("moviebox:v3:$key", $this->cacheTtl, $callback);
    }
}
