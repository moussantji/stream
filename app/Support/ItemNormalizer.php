<?php

namespace App\Support;

use App\Services\MovieBox\SubjectType;
use App\Support\TextSanitizer;

/**
 * Flattens the various raw item shapes returned by the MovieBox backend
 * (search results, trending items, home banner items, detail "subject", etc.)
 * into one consistent structure the frontend can render everywhere.
 */
class ItemNormalizer
{
    /**
     * @param  array<int,mixed>|null  $items
     * @return array<int,array<string,mixed>>
     */
    public static function many(?array $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_array($item) ? self::one($item) : null,
            $items
        )));
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>|null
     */
    public static function one(array $raw): ?array
    {
        // Home "ContentModel" entries nest the real metadata under `subject`.
        $subject = isset($raw['subject']) && is_array($raw['subject']) ? $raw['subject'] : [];

        $get = fn (string $key, $default = null) => $raw[$key] ?? $subject[$key] ?? $default;

        $subjectId = $get('subjectId') ?? ($raw['id'] ?? null);

        if (! $subjectId) {
            return null;
        }

        $type = (int) ($get('subjectType') ?? 0);

        $releaseDate = $get('releaseDate');
        $year = null;
        if (is_string($releaseDate) && strlen($releaseDate) >= 4) {
            $year = (int) substr($releaseDate, 0, 4);
        }

        $rating = self::floatOrNull($get('imdbRatingValue') ?? $get('imdbRate'));

        $rawTitle = (string) ($get('title') ?? $raw['title'] ?? '');
        $title = TextSanitizer::title($rawTitle !== '' ? $rawTitle : null);

        return [
            'subjectId' => (string) $subjectId,
            'subjectType' => $type,
            'typeLabel' => SubjectType::resolve($type)->label(),
            'title' => $title,
            'french' => self::hasFrenchAudio($raw, $subject, $rawTitle.' '.$title),
            'description' => TextSanitizer::description($get('description')),
            'cover' => self::cover($raw, $subject),
            'genres' => self::genres($get('genre')),
            'releaseDate' => $releaseDate ?: null,
            'year' => $year,
            'durationSeconds' => self::intOrNull($get('durationSeconds') ?? $get('seconds') ?? $get('duration')),
            'imdbRating' => $rating > 0 ? $rating : null,
            'country' => $get('countryName'),
            'seasonCount' => self::intOrNull($get('seNum')),
            // v3 uses subjectId everywhere; detailPath kept only for back-compat.
            'detailPath' => $get('detailPath'),
            'hasResource' => (bool) ($get('hasResource') ?? true),
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @param  array<string,mixed>  $subject
     */
    protected static function cover(array $raw, array $subject): ?string
    {
        foreach ([$raw['cover'] ?? null, $raw['image'] ?? null, $subject['cover'] ?? null] as $candidate) {
            if (is_array($candidate) && ! empty($candidate['url'])) {
                return $candidate['url'];
            }
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether the title appears to have a French audio track (for the "VF"
     * badge): French markers in the title, a French language field, or a
     * French entry in a dubs list.
     *
     * @param  array<string,mixed>  $raw
     * @param  array<string,mixed>  $subject
     */
    protected static function hasFrenchAudio(array $raw, array $subject, string $title): bool
    {
        $t = mb_strtolower($title);
        if (str_contains($t, 'français') || str_contains($t, 'française') || str_contains($t, 'francaise')
            || str_contains($t, 'version fr') || preg_match('/(?<![\p{L}])(vf|vff|truefrench|multi)(?![\p{L}])/u', $t)) {
            return true;
        }

        foreach (['language', 'lang', 'lanCode', 'audioLang', 'lanName'] as $key) {
            $v = mb_strtolower((string) ($raw[$key] ?? $subject[$key] ?? ''));
            if ($v === 'fr' || $v === 'fra' || $v === 'fre' || str_starts_with($v, 'fr-')
                || str_contains($v, 'french') || str_contains($v, 'français')) {
                return true;
            }
        }

        $dubs = $raw['dubs'] ?? $subject['dubs'] ?? null;
        if (is_array($dubs)) {
            foreach ($dubs as $dub) {
                if (! is_array($dub)) {
                    continue;
                }
                $code = mb_strtolower((string) ($dub['lanCode'] ?? $dub['code'] ?? ''));
                $name = mb_strtolower((string) ($dub['lanName'] ?? $dub['label'] ?? ''));
                if (str_starts_with($code, 'fr') || str_contains($name, 'fran') || str_contains($name, 'french')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    protected static function genres(mixed $genre): array
    {
        if (is_array($genre)) {
            return array_values(array_filter(array_map('strval', $genre)));
        }

        if (is_string($genre) && $genre !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $genre))));
        }

        return [];
    }

    protected static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 1) : null;
    }
}
