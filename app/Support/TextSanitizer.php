<?php

namespace App\Support;

/**
 * Cleans the noisy, SEO-stuffed titles and descriptions that the upstream
 * provider inherits from scraped sources (YouTube-style keyword spam).
 *
 * Typical garbage looks like:
 *   "FILM COMPLET EN FRANÇAIS (2025) nouveau film d'action | Films d'action …
 *    film d'action,action,film action,films d'actions 2022,… #filmcomplet"
 *
 * The goal is to keep a short, human-readable title and to drop descriptions
 * that are just keyword lists / hashtags rather than real synopses.
 */
class TextSanitizer
{
    /** Clean a title: keep the meaningful part, drop SEO tails & hashtags. */
    public static function title(?string $raw): string
    {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return 'Untitled';
        }

        // Drop everything after the first SEO separator ("|", "•", " - ...").
        $title = preg_split('/\s*[|•]\s*/u', $raw)[0] ?? $raw;

        // Remove hashtags and URLs.
        $title = self::stripHashtagsAndUrls($title);

        // If a huge comma-separated keyword list leaked in, keep only the head.
        if (mb_substr_count($title, ',') >= 3) {
            $title = explode(',', $title)[0];
        }

        // Collapse whitespace.
        $title = trim(preg_replace('/\s+/u', ' ', $title));

        return $title !== '' ? mb_substr($title, 0, 200) : 'Untitled';
    }

    /**
     * Clean a description. Returns null when the text is just keyword spam or
     * hashtags (so the UI can render nothing instead of garbage).
     */
    public static function description(?string $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        // Normalise newlines, strip hashtags / URLs.
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $text = self::stripHashtagsAndUrls($text);

        // Bail out early if the whole thing reads like keyword stuffing.
        if (self::looksLikeKeywordSpam($text)) {
            return null;
        }

        // De-duplicate repeated comma/newline separated fragments (the spam
        // pattern repeats the same block many times).
        $text = self::dedupeFragments($text);

        // Collapse runs of spaces/tabs but keep paragraph breaks.
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        $text = trim($text);

        if ($text === '' || self::looksLikeKeywordSpam($text)) {
            return null;
        }

        // Reasonable upper bound so an overly long blurb stays readable.
        if (mb_strlen($text) > 1200) {
            $text = rtrim(mb_substr($text, 0, 1200)).'…';
        }

        return $text;
    }

    protected static function stripHashtagsAndUrls(string $text): string
    {
        $text = preg_replace('~https?://\S+~i', '', $text);
        $text = preg_replace('/#[^\s#]+/u', '', $text);

        return $text;
    }

    /**
     * Heuristics for detecting keyword-stuffed text (no real sentences, heavy
     * word repetition, or a long comma-separated keyword list).
     */
    protected static function looksLikeKeywordSpam(string $text): bool
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return true;
        }

        $lower = mb_strtolower($normalized);

        // A genuine synopsis usually has at least one real sentence.
        $hasSentence = (bool) preg_match('/[a-zà-ÿ0-9][.!?](\s|$)/u', $lower)
            && mb_strlen($normalized) > 40;

        $commaFragments = count(array_filter(array_map('trim', preg_split('/[,\n]/u', $normalized))));

        // Long comma list with no sentence structure => spam.
        if ($commaFragments >= 8 && ! $hasSentence) {
            return true;
        }

        // Heavy repetition of a single word => spam (e.g. "film d'action" x30).
        preg_match_all("/[\p{L}']{4,}/u", $lower, $m);
        $words = $m[0] ?? [];
        if ($words !== []) {
            $freq = array_count_values($words);
            arsort($freq);
            $maxFreq = (int) reset($freq);
            if ($maxFreq >= 6 && ! $hasSentence) {
                return true;
            }
        }

        return false;
    }

    /** Remove duplicate comma/newline separated fragments, preserving order. */
    protected static function dedupeFragments(string $text): string
    {
        // Split while keeping paragraph structure: dedupe within the flat list.
        $parts = preg_split('/(,|\n)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $seen = [];
        $out = [];

        foreach ($parts as $part) {
            if ($part === ',' || $part === "\n") {
                $out[] = $part;

                continue;
            }
            $key = mb_strtolower(trim($part));
            if ($key === '') {
                $out[] = $part;

                continue;
            }
            if (isset($seen[$key])) {
                continue; // drop the duplicate fragment
            }
            $seen[$key] = true;
            $out[] = $part;
        }

        // Tidy up separators left dangling by removed fragments.
        $result = implode('', $out);
        $result = preg_replace('/(,\s*){2,}/u', ', ', $result);
        $result = preg_replace('/,\s*(\n|$)/u', '$1', $result);

        return trim($result, " ,\n");
    }
}
