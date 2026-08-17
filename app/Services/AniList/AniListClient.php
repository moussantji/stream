<?php

namespace App\Services\AniList;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Public AniList GraphQL access — no API key required.
 * Resolves a title to its adult/anime metadata: `isAdult`, genres
 * (Hentai/Ecchi are explicit genres) and per-tag adult flags.
 */
class AniListClient
{
    protected const ENDPOINT = 'https://graphql.anilist.co';

    protected const TTL = 600;

    protected const QUERY = <<<'GRAPHQL'
query ($q: String) {
  Media(search: $q, type: ANIME) {
    id
    isAdult
    format
    genres
    siteUrl
    startDate { year }
    title { romaji english native }
    tags { name isAdult }
  }
}
GRAPHQL;

    /** @return array<string,mixed>|null */
    public function search(string $title): ?array
    {
        $key = 'guard:v1:anilist:'.md5(mb_strtolower($title));

        return Cache::remember($key, self::TTL, function () use ($title) {
            try {
                $response = Http::timeout(4)
                    ->withHeaders(['Content-Type' => 'application/json', 'Accept' => 'application/json'])
                    ->post(self::ENDPOINT, [
                        'query' => self::QUERY,
                        'variables' => ['q' => $title],
                    ]);

                if (! $response->successful()) {
                    return null;
                }

                $media = $response->json('data.Media');

                return is_array($media) && $media !== [] ? $media : null;
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        });
    }
}