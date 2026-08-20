<?php

namespace App\Support;

/**
 * Version (language) filter: keeps the French version of a title when one
 * exists and never blocks foreign dubs (Hindi, Tamil, …) in the dub selector —
 * a title is simply displayed in French instead when a French version is
 * available. A release that carries only a foreign dub (no French/English
 * audio at all) is not displayed (hasForeignDub / acceptsForRow).
 *
 * Only bootleg / cam releases ([CAM], [TS], [SCR]…) are rejected everywhere.
 *
 * Rules, applied to the [tags] in a title:
 *  - bootleg quality tags → rejected (accepts() only);
 *  - everything else (French, English, Hindi, VOSTFR, no tag…) → accepted;
 *  - a leftover foreign-dub tag (never rewritten to French/English) → the
 *    release has no French/English audio and is not displayed.
 *
 * French preference (preferFrench):
 *  - items are grouped by their tag-less base title;
 *  - within a group, French versions (VF / Version française) win over
 *    VOSTFR, then VO/English, then foreign dubs;
 *  - a group containing only foreign dubs is dropped (Hindi is not blocked
 *    when a French version exists — it is simply not preferred).
 */
class VersionFilter
{
    /** Bootleg / cam release tags — the only ones rejected everywhere. */
    protected const BOOTLEG_TAGS = [
        'cam', 'ts', 'tc', 'hdts', 'hdtc', 'r6', 'dvdscr', 'scr', 'screener',
        'hdcam', 'camrip', 'telesync',
    ];

    /** French dub tags (highest preference). */
    protected const FRENCH_TAGS = [
        'versionfrancaise', 'vf', 'vff', 'francais', 'francaise', 'frenchdub',
        'truefrench', 'fr', 'french',
    ];

    /** French-subtitled tags. */
    protected const VOSTFR_TAGS = [
        'vostfr', 'vost', 'vostf', 'vfstfr', 'soustitre', 'soustitres',
        'soustitresfrancais', 'subtitled', 'subbed', 'sub',
    ];

    /** Original / English audio tags. */
    protected const ORIGINAL_TAGS = [
        'vo', 'versionoriginale', 'originale', 'original',
        'english', 'anglais', 'en',
    ];

    /**
     * Whether a title's version is acceptable. Foreign dubs (Hindi, Bengali,
     * Tamil…) are accepted — the French preference happens in preferFrench(),
     * not by blocking. Only bootleg releases are rejected.
     */
    public static function accepts(string $title, int $subjectType = 0): bool
    {
        foreach (self::tags($title) as $tag) {
            $norm = self::normalize($tag);
            if ($norm === '') {
                continue;
            }
            if (in_array($norm, self::BOOTLEG_TAGS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Looser rule for curated home rows: bootleg markers ([CAM], [TS]…)
     * are tolerated, as the provider itself labels titles that way
     * ("Moana[CAM] [Version française]"). Titles that still carry a
     * foreign-dub tag ("[Hindi]", "[Tamil]"…) are rejected — such a tag is
     * only ever replaced by a French/English one when the audio is actually
     * confirmed (ItemNormalizer::languageize), so a leftover foreign tag
     * means the release has no French/English audio.
     */
    public static function acceptsForRow(string $title): bool
    {
        return ! self::hasForeignDub($title);
    }

    /**
     * Whether the title still carries a foreign-dub tag ("[Hindi]",
     * "[Tamil]", "[Bengali]"…): a non-bootleg tag that is neither French,
     * VOSTFR nor original/English, i.e. one that was not rewritten to a
     * French/English tag — no French/English audio was confirmed.
     */
    public static function hasForeignDub(string $title): bool
    {
        foreach (self::tags($title) as $tag) {
            $norm = self::normalize($tag);
            if ($norm === '' || in_array($norm, self::BOOTLEG_TAGS, true)) {
                continue;
            }
            if (! in_array($norm, self::FRENCH_TAGS, true)
                && ! in_array($norm, self::VOSTFR_TAGS, true)
                && ! in_array($norm, self::ORIGINAL_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove non-acceptable versions from a normalized item list, then
     * prefer French versions within each base-title group.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    public static function apply(array $items): array
    {
        $items = array_values(array_filter($items, function ($item) {
            return self::accepts(
                (string) ($item['title'] ?? ''),
                (int) ($item['subjectType'] ?? 0)
            );
        }));

        return self::preferFrench($items);
    }

    /**
     * Prefer French versions of a title over VOSTFR / VO / foreign dubs:
     * group items by their tag-less base title and keep the best language
     * group. Groups with only foreign dubs are kept untouched. Within the
     * best group, exact duplicate subjectIds are removed.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    public static function preferFrench(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $groups = [];
        foreach ($items as $item) {
            $base = self::baseTitle((string) ($item['title'] ?? ''));
            if ($base === '') {
                $groups[''][self::frenchRank((string) ($item['title'] ?? ''))][] = $item;
                continue;
            }
            $groups[$base][self::frenchRank((string) ($item['title'] ?? ''))][] = $item;
        }

        $out = [];
        foreach ($groups as $byRank) {
            $best = $byRank[3] ?? $byRank[2] ?? $byRank[1] ?? $byRank[0] ?? [];
            $seen = [];
            foreach ($best as $item) {
                $sid = (string) ($item['subjectId'] ?? '');
                if ($sid !== '' && isset($seen[$sid])) {
                    continue;
                }
                $seen[$sid] = true;
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Language preference rank of a title, from its version tags:
     *  3 = French dub, 2 = French subtitles, 1 = original/English, 0 = other.
     *
     * @return int
     */
    public static function frenchRank(string $title): int
    {
        $tags = self::tags($title);
        if ($tags === []) {
            return 1; // untagged = original version
        }

        $rank = 0;
        foreach ($tags as $tag) {
            $norm = self::normalize($tag);
            if ($norm === '') {
                continue;
            }
            if (in_array($norm, self::FRENCH_TAGS, true)) {
                return 3;
            }
            if (in_array($norm, self::VOSTFR_TAGS, true)) {
                $rank = max($rank, 2);
            } elseif (in_array($norm, self::ORIGINAL_TAGS, true)) {
                $rank = max($rank, 1);
            }
        }

        return $rank;
    }

    /**
     * Rewrite foreign-dub tags ("[Hindi]", "[Bengali]"…) as the title's real
     * audio language ("[Français]" or "[Anglais]") when it is confirmed to
     * carry that language. Bootleg markers ([CAM]…) and French/VOSTFR/original
     * tags are never touched.
     */
    public static function frenchize(string $title, bool $hasFrenchAudio): string
    {
        return self::languageize($title, $hasFrenchAudio ? 'fr' : null);
    }

    /**
     * Rewrite foreign-dub tags ("[Hindi]", "[Bengali]"…) as "[Français]" when
     * $lang is 'fr' or "[Anglais]" when $lang is 'en'. Tags of the preferred
     * language and bootleg markers are never touched.
     */
    public static function languageize(string $title, ?string $lang): string
    {
        if ($lang === null || ! str_contains($title, '[')) {
            return $title;
        }

        $replacement = $lang === 'en' ? '[Anglais]' : '[Français]';

        return (string) preg_replace_callback('/\[([^\]]+)\]/u', function (array $m) use ($replacement): string {
            $norm = self::normalize($m[1] ?? '');
            if ($norm === '' || in_array($norm, self::BOOTLEG_TAGS, true)) {
                return $m[0];
            }
            if (self::frenchRank($m[0]) === 0) {
                return $replacement;
            }

            return $m[0];
        }, $title);
    }

    /**
     * Tag-less base title ("A Salad Bowl of Eccentrics [Hindi]" →
     * "A Salad Bowl of Eccentrics").
     */
    protected static function baseTitle(string $title): string
    {
        return trim((string) preg_replace('/\[[^\]]*\]/u', '', $title));
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