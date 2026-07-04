<?php

namespace App\Support;

/**
 * Hides configured content categories from DISCOVERY surfaces (home rows,
 * trending, category browsing, recommendations, local library) based on a
 * keyword list. It is intentionally NOT applied to search/suggest, so filtered
 * titles stay reachable when a user explicitly searches for them.
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
        $haystack = mb_strtolower(trim((string) ($item['title'] ?? '').' '.($item['description'] ?? '')));
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
