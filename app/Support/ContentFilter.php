<?php

namespace App\Support;

use App\Models\BlockedTitle;
use Illuminate\Support\Facades\Cache;

/**
 * Hides configured content categories from DISCOVERY surfaces (home rows,
 * trending, category browsing, recommendations, local library) based on a
 * keyword list AND an admin-managed list of blocked titles/subjectIds. It is
 * intentionally NOT applied to search/suggest results page, so filtered titles
 * stay reachable when a user explicitly searches for them.
 *
 * Matching strategy:
 *  - genres: exact (case-insensitive) match against a blocked keyword;
 *  - title / description: whole-word match (word boundaries) to avoid
 *    false positives on substrings.
 */
class ContentFilter
{
    /** @var array<int,string>|null */
    protected static ?array $keywords = null;

    /** @var array<int,string>|null */
    protected static ?array $blockedTerms = null;

    public const BLOCKED_CACHE_KEY = 'content:blocked_titles';

    /**
     * Remove blocked items from a normalized item list.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    public static function apply(array $items): array
    {
        if (! self::enabled()) {
            return $items;
        }

        return array_values(array_filter($items, fn ($item) => ! self::isBlocked($item)));
    }

    /**
     * @param  array<string,mixed>  $item
     */
    public static function isBlocked(array $item): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $title = mb_strtolower(trim((string) ($item['title'] ?? '')));

        // Admin-managed blocklist: exact subjectId or title substring.
        $blocked = self::blockedTerms();
        if ($blocked !== []) {
            $sid = mb_strtolower((string) ($item['subjectId'] ?? ''));
            foreach ($blocked as $term) {
                if ($term === '') {
                    continue;
                }
                if ($sid !== '' && $sid === $term) {
                    return true;
                }
                if ($title !== '' && str_contains($title, $term)) {
                    return true;
                }
            }
        }

        $keywords = self::keywords();
        if ($keywords === []) {
            return false;
        }

        // Genres: exact case-insensitive comparison.
        foreach ((array) ($item['genres'] ?? []) as $genre) {
            if (in_array(mb_strtolower(trim((string) $genre)), $keywords, true)) {
                return true;
            }
        }

        // Title + description: whole-word match.
        $haystack = mb_strtolower(trim($title.' '.($item['description'] ?? '')));
        if ($haystack === '') {
            return false;
        }

        foreach ($keywords as $word) {
            if ($word === '') {
                continue;
            }
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $haystack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin-managed blocked terms (titles/subjectIds), cached for 5 minutes.
     *
     * @return array<int,string>
     */
    protected static function blockedTerms(): array
    {
        if (self::$blockedTerms !== null) {
            return self::$blockedTerms;
        }

        try {
            self::$blockedTerms = Cache::remember(self::BLOCKED_CACHE_KEY, 300, function () {
                return BlockedTitle::query()->pluck('term')
                    ->map(fn ($t) => mb_strtolower(trim((string) $t)))
                    ->filter()
                    ->values()
                    ->all();
            });
        } catch (\Throwable $e) {
            self::$blockedTerms = [];
        }

        return self::$blockedTerms;
    }

    protected static function enabled(): bool
    {
        return (bool) config('moviebox.content_filter_enabled', true);
    }

    /**
     * @return array<int,string>
     */
    protected static function keywords(): array
    {
        return self::$keywords ??= array_values(array_filter((array) config('moviebox.blocked_keywords', [])));
    }
}
