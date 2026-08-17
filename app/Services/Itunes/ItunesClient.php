<?php

namespace App\Services\Itunes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Public iTunes Search API access — no API key required.
 * Resolves a film/series title to Apple's rating (`contentAdvisoryRating`:
 * R, NC-17, TV-MA…), genres and long description, which let the metadata
 * guard drop explicit adult titles without TMDB.
 */
class ItunesClient
{
    protected const ENDPOINT = 'https://itunes.apple.com/search';

    protected const TTL = 600;

    /** @return array<string,mixed>|null */
    public function search(string $title, string $kind = 'movie'): ?array
    {
        $key = 'guard:v1:itunes:'.$kind.':'.md5(mb_strtolower($title));

        return Cache::remember($key, self::TTL, function () use ($title, $kind) {
            try {
                $params = [
                    'term' => $title,
                    'media' => $kind === 'tv' ? 'tvShow' : 'movie',
                    'entity' => $kind === 'tv' ? 'tvSeason' : 'movie',
                    'limit' => 5,
                ];

                $response = Http::timeout(8)->get(self::ENDPOINT, $params);

                if (! $response->successful()) {
                    return null;
                }

                $results = $response->json('results');
                if (! is_array($results) || $results === []) {
                    return null;
                }

                foreach ($results as $result) {
                    if (! is_array($result)) {
                        continue;
                    }
                    $name = $kind === 'tv'
                        ? (string) ($result['collectionName'] ?? '')
                        : (string) ($result['trackName'] ?? '');
                    if (mb_strtolower($name) !== '' && str_contains(mb_strtolower($title), mb_substr(mb_strtolower($name), 0, 8))) {
                        return $this->shape($result);
                    }
                }

                return $this->shape($results[0]);
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        });
    }

    /** @param  array<string,mixed>  $result */
    protected function shape(array $result): array
    {
        return [
            'title' => (string) ($result['trackName'] ?? $result['collectionName'] ?? ''),
            'contentAdvisoryRating' => (string) ($result['contentAdvisoryRating'] ?? ''),
            'genres' => array_values(array_filter((array) ($result['genres'] ?? []))),
            'description' => (string) ($result['longDescription'] ?? $result['shortDescription'] ?? ''),
            'releaseDate' => (string) ($result['releaseDate'] ?? ''),
        ];
    }
}