<?php

namespace App\Services\MovieBox;

/**
 * Extracts the rich item details embedded in a MovieBox detail page.
 *
 * The detail HTML page ships a Nuxt-style "flattened / index-referenced" JSON
 * payload inside a <script type="application/json"> tag. Values in the payload
 * reference each other by their integer position in the top-level array. This
 * class faithfully re-implements the de-referencing algorithm used by the
 * upstream Python project so we can recover season / episode information,
 * cast, reviews and other metadata.
 *
 * This is best-effort: if the page layout changes the method returns null and
 * callers should degrade gracefully (movies still play; series default to
 * season 1 and discover availability from the play/download response).
 */
class DetailExtractor
{
    /**
     * @return array<string,mixed>|null The de-referenced `state` object, or null.
     */
    public static function extract(string $html): ?array
    {
        if (! preg_match('/<script[^>]*type=["\']application\/json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return null;
        }

        $data = json_decode(trim($matches[1]), true);

        if (! is_array($data)) {
            return null;
        }

        $resolve = function ($value) use (&$resolve, $data) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $out = [];
                    foreach ($value as $index) {
                        $out[] = $resolve(is_int($index) ? ($data[$index] ?? null) : $index);
                    }

                    return $out;
                }

                $out = [];
                foreach ($value as $key => $index) {
                    $out[$key] = $resolve($data[$index] ?? null);
                }

                return $out;
            }

            return $value;
        };

        $extracts = [];
        foreach ($data as $entry) {
            if (is_array($entry) && ! array_is_list($entry) && $entry !== []) {
                $details = [];
                foreach ($entry as $key => $index) {
                    $details[$key] = $resolve($data[$index] ?? null);
                }
                $extracts[] = $details;
            }
        }

        if ($extracts === []) {
            return null;
        }

        $state = $extracts[0]['state'] ?? null;

        if (! is_array($state) || ! isset($state[1]) || ! is_array($state[1])) {
            return null;
        }

        // Keys are prefixed with a two-character marker (e.g. "$s") that we strip.
        $result = [];
        foreach ($state[1] as $key => $value) {
            $result[is_string($key) ? substr($key, 2) : $key] = $value;
        }

        return $result;
    }
}
