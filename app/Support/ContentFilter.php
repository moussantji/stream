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

    /** @var array<int,string>|null */
    protected static ?array $hardKeywords = null;

    public const BLOCKED_CACHE_KEY = 'content:blocked_titles';

    /**
     * Remove blocked items from a normalized item list. Version filtering
     * (French / English / VOSTFR / VO only) is applied first, then the
     * admin-managed blocklist.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    public static function apply(array $items): array
    {
        $items = VersionFilter::apply($items);

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

        $title = self::normalize((string) ($item['title'] ?? ''));
        $desc = self::normalize((string) ($item['description'] ?? ''));

        // Censored spellings (f**k, f*ck, s*x, p*rn, f*** buddy) defeat keyword
        // matching after normalization *strips* the intercalated stars, so they
        // are detected against the raw, untouched title/description.
        if (self::matchesPornRegex((string) ($item['title'] ?? ''))
            || self::matchesPornRegex((string) ($item['description'] ?? ''))) {
            return true;
        }

        // Admin-managed blocklist: exact subjectId or title substring.
        $blocked = self::blockedTerms();
        if ($blocked !== []) {
            $sid = self::normalize((string) ($item['subjectId'] ?? ''));
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

        // Unambiguous porn vocabulary matched anywhere (no word boundaries).
        $hard = self::hardBlockKeywords();
        if ($hard !== []) {
            $haystack = trim($title.' '.$desc);
            if ($haystack !== '') {
                foreach ($hard as $token) {
                    if ($token !== '' && str_contains($haystack, $token)) {
                        return true;
                    }
                }
            }
        }

        $keywords = self::keywords();
        if ($keywords === []) {
            return false;
        }

        // Genres: accent-insensitive compare (érotique ≈ erotique), exact or
        // substring (e.g. "erotic" matches "Érotico").
        foreach ((array) ($item['genres'] ?? []) as $genre) {
            $genre = self::normalize((string) $genre);
            if ($genre === '') {
                continue;
            }
            foreach ($keywords as $word) {
                if ($word === '') {
                    continue;
                }
                if ($genre === $word || str_contains($genre, $word)) {
                    return true;
                }
            }
        }

        // Title + description: whole-word match. Multi-word phrases are matched
        // as substrings so e.g. "sex tape" is caught.
        $haystack = trim($title.' '.$desc);
        if ($haystack === '') {
            return false;
        }

        if (self::matchesKeywordHaystack($haystack, $keywords)) {
            return true;
        }

        // Keywords like "sex" collapse href "/sep/life" separators once the
        // punctuation is stripped ("sex/life" -> "sexlife") and escape the
        // whole-word boundary; re-run against the accent-flattened BUT
        // punctuation-preserving form so "sex/life" is caught while "sextape"
        // alone is not.
        $rawHay = self::flatten((string) ($item['title'] ?? '').' '.(string) ($item['description'] ?? ''));
        if ($rawHay !== '' && $rawHay !== $haystack && self::matchesKeywordHaystack($rawHay, $keywords)) {
            return true;
        }

        // Junk / softcore detection by signal class (no per-title blocks):
        // this family of uploads shares a low rating, a short synopsis and no
        // "serious" genre, so they can be filtered systematically.
        if (self::isJunkQuality($item)) {
            return true;
        }

        return false;
    }

    /**
     * Systemic junk filter for the softcore / scam-upload family (e.g. "Init"):
     *  - explicit softcore vocabulary in title/description → always blocked;
     *  - otherwise a very low rating + a short synopsis + no safe genre marks
     *    the upload as a padding entry regardless of its title.
     *
     * @param  array<string,mixed>  $item
     */
    protected static function isJunkQuality(array $item): bool
    {
        $title = self::normalize((string) ($item['title'] ?? ''));
        $desc = self::normalize((string) ($item['description'] ?? ''));

        $rating = (float) ($item['imdbRating'] ?? 0);
        $genres = array_map(fn ($g) => self::normalize((string) $g), (array) ($item['genres'] ?? []));

        // Safety valve: never filter titles with a standard synopsis.
        if (mb_strlen($desc) >= (int) config('moviebox.junk_min_desc', 300)) {
            return false;
        }

        foreach (self::softKeywords() as $word) {
            if ($word === '' || str_contains($word, ' ') === false) {
                continue;
            }
            if (str_contains($title, $word) || ($desc !== '' && str_contains($desc, $word))) {
                return true;
            }
        }

        $ratingFloor = (float) config('moviebox.junk_rating', 4.8);
        if ($rating <= 0 || $rating >= $ratingFloor) {
            return false;
        }

        // A "serious" genre protects legit low-rated cinema.
        $safe = array_map(fn ($g) => self::normalize((string) $g), (array) config('moviebox.junk_safe_genres', []));
        foreach ($safe as $s) {
            if ($s !== '' && in_array($s, $genres, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Softcore / padded-upload vocabulary, matched as accent-insensitive
     * phrases against the title and description.
     *
     * @return array<int,string>
     */
    protected static function softKeywords(): array
    {
        static $soft = null;

        return $soft ??= array_values(array_filter(array_map(
            fn ($w) => self::normalize((string) $w),
            (array) config('moviebox.soft_keywords', [])
        )));
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
                    ->map(fn ($t) => self::normalize((string) $t))
                    ->filter()
                    ->values()
                    ->all();
            });
        } catch (\Throwable $e) {
            self::$blockedTerms = [];
        }

        return self::$blockedTerms;
    }

    /** Lowercase, accent-free form used for all comparisons (érotique ≈ erotique). */
    protected static function normalize(string $text): string
    {
        $t = mb_strtolower(trim($text), 'UTF-8');
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t;

        return trim((string) preg_replace('/[^\p{L}\p{N} ]/u', '', $t));
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
        return self::$keywords ??= array_values(array_filter(array_map(
            fn ($w) => self::normalize((string) $w),
            (array) config('moviebox.blocked_keywords', [])
        )));
    }

    /**
     * Unambiguous porn vocabulary, matched as raw substrings.
     *
     * @return array<int,string>
     */
    protected static function hardBlockKeywords(): array
    {
        return self::$hardKeywords ??= array_values(array_filter(array_map(
            fn ($w) => self::normalize((string) $w),
            (array) config('moviebox.hard_block_keywords', [])
        )));
    }

    /**
     * Detect censored porn spellings on a plain (accents/stars untouched) string:
     * f*k / f*ck / f**k, s*x, p*rn, "f*** buddy", … Normalization strips the
     * stars so these never match keywords, hence the upfront regex pass.
     */
    protected static function matchesPornRegex(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }

        foreach (self::pornRegexes() as $pattern) {
            if (preg_match($pattern, $raw) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    protected static function pornRegexes(): array
    {
        static $patterns = [
            '/\bf[\*_\.]{1,3}ck\b/iu',          // f*ck, f**ck, f***ck
            '/\bf[\*_\.]{1,3}k\b/iu',           // f*k, f**k, f***k
            '/\bf[\*_\.]{1,3}\s*budd(?:y|ies)\b/iu', // "f*** buddy"
            '/fux+/iu',                      // fux, fuxxxxx (euphemism)
            '/s[\*_\.]{1,3}x/iu',               // s*x
            '/p[\*_\.]{1,3}rn/iu',              // p*rn
            '/p[\*_\.]{1,3}rn[\*_\.]{1,3}hub/iu', // p*rn*hub
        ];

        return $patterns;
    }

    /**
     * Whole-word / phrase keyword match against a given (prepared) haystack.
     *
     * @param  array<int,string>  $keywords
     */
    protected static function matchesKeywordHaystack(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $word) {
            if ($word === '') {
                continue;
            }
            if (str_contains($word, ' ')) {
                if (str_contains($haystack, $word)) {
                    return true;
                }
                continue;
            }
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $haystack)) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase accent-flattened text that keeps punctuation/separators. */
    protected static function flatten(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t;

        return trim((string) $t);
    }
}
