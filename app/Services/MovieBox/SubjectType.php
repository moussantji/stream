<?php

namespace App\Services\MovieBox;

/**
 * Content types mapped to the integer identifiers used by the MovieBox backend.
 */
enum SubjectType: int
{
    case ALL = 0;
    case MOVIES = 1;
    case TV_SERIES = 2;
    case EDUCATION = 5;
    case MUSIC = 6;
    case ANIME = 7;
    case OTHER = 8;
    case UNKNOWN = 9;

    /**
     * Resolve a subject type from a loose value (int, numeric string or name).
     */
    public static function resolve(int|string|null $value): self
    {
        if ($value === null || $value === '') {
            return self::ALL;
        }

        if (is_numeric($value)) {
            return self::tryFrom((int) $value) ?? self::ALL;
        }

        $name = strtoupper(str_replace([' ', '-'], '_', (string) $value));

        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        // A couple of friendly aliases.
        return match ($name) {
            'MOVIE', 'MOVIES' => self::MOVIES,
            'SERIES', 'TV', 'TVSERIES', 'SHOW', 'SHOWS' => self::TV_SERIES,
            default => self::ALL,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ALL => 'All',
            self::MOVIES => 'Movies',
            self::TV_SERIES => 'TV Series',
            self::EDUCATION => 'Education',
            self::MUSIC => 'Music',
            self::ANIME => 'Anime',
            self::OTHER => 'Other',
            self::UNKNOWN => 'Unknown',
        };
    }
}
