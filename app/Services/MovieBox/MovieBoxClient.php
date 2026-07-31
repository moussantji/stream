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

    protected string $deviceId = '';

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

        $this->applyDeviceIdentity($config);
    }

    /**
     * Replace the (flagged) hardcoded device_id/gaid in client_info with a
     * stable per-install identity so the streaming endpoints stop returning
     * "find no content" (406).
     *
     * @param  array<string,mixed>  $config
     */
    protected function applyDeviceIdentity(array $config): void
    {
        $identity = $this->resolveDeviceIdentity($config);
        $this->deviceId = $identity['device_id'];

        $ci = json_decode($this->clientInfo, true);
        if (is_array($ci)) {
            $ci['device_id'] = $identity['device_id'];
            $ci['gaid'] = $identity['gaid'];
            $this->clientInfo = json_encode($ci, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Resolve the device identity: an explicit config/env override, else a
     * random identity persisted to storage/app/moviebox/device.json (created
     * once, reused forever — survives cache clears and deploys).
     *
     * @param  array<string,mixed>  $config
     * @return array{device_id:string,gaid:string}
     */
    protected function resolveDeviceIdentity(array $config): array
    {
        $envDevice = $config['device_id'] ?? null;
        $envGaid = $config['gaid'] ?? null;
        if ($envDevice && $envGaid) {
            return ['device_id' => (string) $envDevice, 'gaid' => (string) $envGaid];
        }

        $path = storage_path('app/moviebox/device.json');
        if (is_file($path)) {
            $data = json_decode((string) @file_get_contents($path), true);
            if (is_array($data) && ! empty($data['device_id']) && ! empty($data['gaid'])) {
                return ['device_id' => (string) $data['device_id'], 'gaid' => (string) $data['gaid']];
            }
        }

        $identity = ['device_id' => bin2hex(random_bytes(16)), 'gaid' => self::randomGaid()];
        @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, json_encode($identity));

        return $identity;
    }

    protected static function randomGaid(): string
    {
        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)), bin2hex(random_bytes(6))
        );
    }

    /** Token cache key, namespaced by device so a device change re-bootstraps. */
    protected function tokenCacheKey(): string
    {
        return self::TOKEN_CACHE_KEY.':'.substr(md5($this->deviceId), 0, 10);
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
     * paginated across episodes for series). The API caps perPage at 20.
     */
    public function resource(string $subjectId, int $resolution = 1080, int $page = 1, int $perPage = 20): array
    {
        return $this->getData(
            self::RESOURCE,
            ['subjectId' => $subjectId, 'resolution' => $resolution, 'page' => $page, 'perPage' => max(1, min(20, $perPage))],
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

    /**
     * Raw diagnostic request: performs a signed call and returns the status,
     * a few revealing response headers and a body snippet WITHOUT throwing on
     * failure. Lets us see who emits e.g. a 406 (a WAF/CDN like Cloudflare or
     * mod_security vs. the MovieBox app itself).
     *
     * @return array<string,mixed>
     */
    public function rawProbe(string $path, array $params = [], bool $playMode = false): array
    {
        $this->ensureBootstrapped();
        [$base, $response] = $this->request('GET', $path, $params, playMode: $playMode);

        $headers = [];
        foreach (['Server', 'Content-Type', 'CF-RAY', 'cf-mitigated', 'Via', 'X-Cache', 'WWW-Authenticate', 'Set-Cookie'] as $h) {
            $v = $response->header($h);
            if ($v !== null && $v !== '') {
                $headers[$h] = is_array($v) ? implode(', ', $v) : $v;
            }
        }

        return [
            'host' => $base,
            'status' => $response->status(),
            'headers' => $headers,
            'bodySnippet' => mb_substr((string) $response->body(), 0, 500),
        ];
    }

    /** Raw diagnostic probe of the `resource` endpoint. */
    public function rawResourceProbe(string $subjectId): array
    {
        return $this->rawProbe(self::RESOURCE, [
            'subjectId' => $subjectId, 'resolution' => 1080, 'page' => 1, 'perPage' => 20,
        ]);
    }

    /**
     * Probe the `resource` endpoint against EVERY host in the pool to detect a
     * per-host "find no content" (region-routed hosts can differ). Reports the
     * status + app code/message per host without throwing.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rawResourceAllHosts(string $subjectId): array
    {
        return $this->rawProbeAllHosts(self::RESOURCE, [
            'subjectId' => $subjectId, 'resolution' => 1080, 'page' => 1, 'perPage' => 20,
        ]);
    }

    /**
     * Try the `resource` endpoint with several parameter shapes to discover
     * which one (if any) the API currently accepts — quickly reveals an API
     * contract change (e.g. a param that must be added/removed/renamed).
     *
     * @return array<int,array<string,mixed>>
     */
    public function rawResourceVariants(string $subjectId): array
    {
        $variants = [
            'current (res=1080,page,perPage)' => ['subjectId' => $subjectId, 'resolution' => 1080, 'page' => 1, 'perPage' => 20],
            'no resolution' => ['subjectId' => $subjectId, 'page' => 1, 'perPage' => 20],
            'subjectId only' => ['subjectId' => $subjectId],
            'with se/ep = 1' => ['subjectId' => $subjectId, 'se' => 1, 'ep' => 1, 'resolution' => 1080, 'page' => 1, 'perPage' => 20],
            'res=0' => ['subjectId' => $subjectId, 'resolution' => 0, 'page' => 1, 'perPage' => 20],
            'subjectId as int-ish string, page from 0' => ['subjectId' => $subjectId, 'resolution' => 1080, 'page' => 0, 'perPage' => 20],
        ];

        $out = [];
        foreach ($variants as $label => $params) {
            $out[] = ['variant' => $label] + $this->probeOnce(self::RESOURCE, $params);
        }

        return $out;
    }

    /**
     * Diagnostic: bootstrap a FRESH random device identity + token and retry
     * `resource`. If this succeeds where the configured device fails, the
     * hardcoded device_id/gaid is flagged and should be randomised per install.
     *
     * @return array<string,mixed>
     */
    public function probeFreshDevice(string $subjectId): array
    {
        $deviceId = bin2hex(random_bytes(16));
        $gaid = sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)), bin2hex(random_bytes(6))
        );
        $ci = json_decode($this->clientInfo, true) ?: [];
        $ci['device_id'] = $deviceId;
        $ci['gaid'] = $gaid;

        $savedCi = $this->clientInfo;
        $savedToken = $this->token;
        $savedBootstrapped = $this->bootstrapped;

        $this->clientInfo = json_encode($ci, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->token = null;

        $result = ['deviceId' => $deviceId];
        try {
            // Fresh token for the new device (no auth header on this call).
            $this->request('GET', self::MAIN_PAGE, ['page' => 1, 'tabId' => 0, 'version' => '']);
            $result['freshTokenOk'] = is_string($this->token) && $this->token !== '' && $this->token !== $savedToken;
            $result['resource'] = $this->probeOnce(self::RESOURCE, [
                'subjectId' => $subjectId, 'resolution' => 1080, 'page' => 1, 'perPage' => 20,
            ]);
        } catch (Throwable $e) {
            $result['error'] = class_basename($e).': '.$e->getMessage();
        } finally {
            $this->clientInfo = $savedCi;
            $this->token = $savedToken;
            $this->bootstrapped = $savedBootstrapped;
            if (is_string($savedToken) && $savedToken !== '') {
                Cache::put($this->tokenCacheKey(), $savedToken, $this->tokenTtl);
            }
        }

        return $result;
    }

    /** Single signed GET on the active host, summarised, never throws. */
    protected function probeOnce(string $path, array $params, bool $playMode = false): array
    {
        try {
            $query = $params === [] ? '' : http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $pathWithQuery = $query === '' ? $path : $path.'?'.$query;
            $response = $this->buildClient('GET', $path, $params, null, $playMode)->get($this->activeBase.$pathWithQuery);
            $this->absorbToken($response);
            $json = json_decode((string) $response->body(), true);
            $data = is_array($json) ? ($json['data'] ?? null) : null;

            return [
                'status' => $response->status(),
                'code' => is_array($json) ? ($json['code'] ?? null) : null,
                'message' => is_array($json) ? ($json['message'] ?? null) : null,
                'listCount' => is_array($data) && is_array($data['list'] ?? null) ? count($data['list']) : null,
            ];
        } catch (Throwable $e) {
            return ['error' => class_basename($e).': '.$e->getMessage()];
        }
    }

    /**
     * Perform the same signed GET against every host and summarise each result.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rawProbeAllHosts(string $path, array $params = [], bool $playMode = false): array
    {
        $this->ensureBootstrapped();

        $query = $params === [] ? '' : http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $pathWithQuery = $query === '' ? $path : $path.'?'.$query;

        $out = [];
        foreach ($this->hostPool as $base) {
            try {
                $response = $this->buildClient('GET', $path, $params, null, $playMode)->get($base.$pathWithQuery);
                $this->absorbToken($response);
                $json = json_decode((string) $response->body(), true);
                $data = is_array($json) ? ($json['data'] ?? null) : null;
                $out[] = [
                    'host' => $base,
                    'status' => $response->status(),
                    'code' => is_array($json) ? ($json['code'] ?? null) : null,
                    'message' => is_array($json) ? ($json['message'] ?? null) : null,
                    'listCount' => is_array($data) && is_array($data['list'] ?? null) ? count($data['list']) : null,
                ];
            } catch (Throwable $e) {
                $out[] = ['host' => $base, 'error' => class_basename($e).': '.$e->getMessage()];
            }
        }

        return $out;
    }

    /** Raw diagnostic probe of the `play-info` endpoint. */
    public function rawPlayInfoProbe(string $subjectId, int $se = 0, int $ep = 0): array
    {
        return $this->rawProbe(self::PLAY_INFO, [
            'subjectId' => $subjectId, 'se' => $se, 'ep' => $ep,
        ], playMode: true);
    }

    // -----------------------------------------------------------------
    // Transport
    // -----------------------------------------------------------------

    public function ensureBootstrapped(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $cached = Cache::get($this->tokenCacheKey());
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
            Cache::put($this->tokenCacheKey(), $this->token, $this->tokenTtl);
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
