<?php

namespace App\Support;

/**
 * Version (language) filter: keeps only French, English and French-subtitled
 * (VOSTFR) versions of a title, everywhere on the site, so a title like
 * "From" is only reachable through its French / English / original version —
 * never through its Hindi, Bengali or bootleg (CAM) upload.
 *
 * Rules, applied to the [tags] in a title:
 *  - any tag matching a non-FR/EN language or a bootleg quality → rejected;
 *  - French / English / VOSTFR tags → accepted;
 *  - unknown tags (e.g. release markers like "BDRip") → accepted;
 *  - no tags at all (the original version) → accepted.
 *
 * Anime: same rules — a French dub is preferred, and a VOSTFR / subtitled
 * version is accepted when there is no dub (the player proxies subtitle
 * tracks anyway).
 */
class VersionFilter
{
    /** Language tags that are NOT French / English / VOSTFR. */
    protected const REJECTED_TAGS = [
        'hindi', 'hindicam', 'bengali', 'urdu', 'telugu', 'tamil', 'malayalam',
        'marathi', 'gujarati', 'punjabi', 'oriya', 'odia', 'kannada', 'assamese',
        'sindhi', 'nepali', 'sinhala', 'arabic', 'chinese', 'mandarin',
        'cantonese', 'japanese', 'korean', 'thai', 'vietnamese', 'indonesian',
        'malay', 'tagalog', 'filipino', 'persian', 'farsi', 'hebrew', 'turkish',
        'german', 'spanish', 'portuguese', 'italian', 'dutch', 'polish',
        'russian', 'greek',
        // Short / API dub codes (esla = Español Latinoamérica, ptbr = Português).
        'esla', 'esl', 'es', 'espa', 'ptbr', 'pt', 'ptbr1080', 'hin', 'ben',
        'tam', 'tel', 'urd', 'mal', 'kan', 'mar', 'de', 'ja', 'ko', 'zh',
        'chi', 'zho', 'ita', 'it', 'ru', 'ara',
        // Bootleg / cam releases.
        'cam', 'ts', 'tc', 'hdts', 'hdtc', 'r6', 'dvdscr', 'scr', 'screener',
    ];

    /** French / English / subtitled / original language tags. */
    protected const ACCEPTED_TAGS = [
        'versionfrancaise', 'vf', 'vff', 'francais', 'french', 'frenchdub', 'fr',
        'english', 'anglais', 'en',
        'vo', 'versionoriginale', 'originale', 'original',
        'vostfr', 'vost', 'vostf', 'vfstfr', 'soustitre', 'soustitres',
        'soustitresfrancais', 'subtitled', 'subbed', 'sub', 'multi', 'dual',
    ];

    /**
     * Whether a title's version is acceptable (French / English / VOSTFR /
     * original). $subjectType is kept in the signature for the anime case:
     * anime relies on the same rules, subtitled versions being accepted
     * whenever there is no dub.
     */
    public static function accepts(string $title, int $subjectType = 0): bool
    {
        $tags = self::tags($title);
        if ($tags === []) {
            return true; // original version (typically English audio)
        }

        foreach ($tags as $tag) {
            if (preg_match('/\p{Arabic}/u', $tag)) {
                return false; // "ترجمة عربية" (Arabic dub) and the like
            }
            $norm = self::normalize($tag);
            if ($norm === '') {
                continue;
            }
            if (in_array($norm, self::REJECTED_TAGS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove non-acceptable versions from a normalized item list.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    public static function apply(array $items): array
    {
        return array_values(array_filter($items, function ($item) {
            return self::accepts(
                (string) ($item['title'] ?? ''),
                (int) ($item['subjectType'] ?? 0)
            );
        }));
    }

    /**
     * Version tags found in a title ("From [Version française]" → [Version française]).
     *
     * @return array<int,string>
     */
    protected static function tags(string $title): array
    {
        if (! preg_match_all('/\[([^\]]+)\]/u', $title, $m)) {
            return [];
        }

        return array_map('trim', $m[1]);
    }

    /** Lowercase, accent-free, letters-only form of a tag. */
    protected static function normalize(string $tag): string
    {
        $t = mb_strtolower($tag, 'UTF-8');
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t;

        return (string) preg_replace('/[^a-z]/', '', $t);
    }
}
