<?php

namespace App\Services\MovieBox;

/**
 * Request signing for the MovieBox mobile ("wefeed-mobile-bff") API.
 *
 * Every request must carry two headers:
 *   - X-Client-Token:  "<ts>,<md5(reverse(<ts>))>"
 *   - x-tr-signature:  "<ts>|2|<base64(hmac_md5(canonical, secret))>"
 *
 * The canonical string is:
 *   METHOD\nACCEPT\nCONTENT_TYPE\nBODY_LEN\nTS\nBODY_MD5\nPATH?SORTED_QUERY
 *
 * where the query keys are sorted and values are NOT percent-encoded, and the
 * body md5 is computed over the first 100 KiB of the (utf-8) body. This mirrors
 * the algorithm used by the upstream `Simatwa/moviebox-api` v3 client.
 */
class Signer
{
    private const BODY_MAX_BYTES = 102_400;

    /** X-Client-Token header value. */
    public static function clientToken(int $timestampMs): string
    {
        $ts = (string) $timestampMs;
        $reversed = strrev($ts);

        return $ts.','.md5($reversed);
    }

    /** x-tr-signature header value. */
    public static function signature(
        string $method,
        string $accept,
        string $contentType,
        string $path,
        array $params,
        ?string $body,
        string $secretBase64,
        int $timestampMs,
    ): string {
        $canonicalUrl = self::canonicalUrl($path, $params);

        if ($body !== null) {
            $truncated = substr($body, 0, self::BODY_MAX_BYTES);
            $bodyHash = md5($truncated);
            $bodyLength = (string) strlen($body);
        } else {
            $bodyHash = '';
            $bodyLength = '';
        }

        $canonical = implode("\n", [
            strtoupper($method),
            $accept,
            $contentType,
            $bodyLength,
            (string) $timestampMs,
            $bodyHash,
            $canonicalUrl,
        ]);

        $secret = base64_decode(self::pad($secretBase64), true) ?: '';
        $mac = hash_hmac('md5', $canonical, $secret, true);

        return $timestampMs.'|2|'.base64_encode($mac);
    }

    /** Build "path?key=value&…" with keys sorted and values left un-encoded. */
    public static function canonicalUrl(string $path, array $params): string
    {
        if ($params === []) {
            return $path;
        }

        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key.'='.self::stringify($value);
        }

        return $path.'?'.implode('&', $parts);
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private static function pad(string $b64): string
    {
        $mod = strlen($b64) % 4;

        return $mod === 0 ? $b64 : $b64.str_repeat('=', 4 - $mod);
    }
}
