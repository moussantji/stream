<?php

namespace App\Services\Jikan;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Public Jikan (MyAnimeList) REST access — no API key required.
 * Cross-check source for anime rows: the "Hentai" genre and
 * "Rx - Hentai" ratings are explicit on MAL.
 */
class JikanClient
{
    protected const ENDPOINT = 'https://api.jikan.moe/v4/anime';

    protected const TTL = 600;

    /** @return array<string,mixed>|null */
    public function search(string $title): ?array
    {
        $key = 'guard:v1:jikan:'.md5(mb_strtolower($title));

        return Cache::remember($key, self::TTL, function () use ($title) {
            try {
                $response = Http::timeout(4)->get(self::ENDPOINT, [
                    'q' => $title,
                    'limit' => 3,
                    'sfw' => 'false',
                ]);

                if (! $response->successful()) {
                    return null;
                }

                $data = $response->json('data');
                if (! is_array($data) || $data === []) {
                    return null;
                }

                $hit = $data[0];
                if (! is_array($hit)) {
                    return null;
                }

                return [
                    'mal_id' => $hit['mal_id'] ?? null,
                    'title' => $hit['title'] ?? '',
                    'genres' => array_values(array_filter(array_map(
                        static fn ($g) => is_array($g) ? ($g['name'] ?? '') : '',
                        $hit['genres'] ?? []
                    ))),
                    'rating' => $hit['rating'] ?? '',
                    'type' => $hit['type'] ?? '',
                ];
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        });
    }
}