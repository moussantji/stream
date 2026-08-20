<?php

namespace App\Support;

/**
 * Compact, reversible encoding of a subject id (+ subject type) into a short
 * URL-safe token: "7921699950789289456:02" (21 chars) becomes a ~13-char
 * base62 slug ("/t/…") instead of the long /title?subjectId=… query string.
 *
 * The subject id is a decimal string (19+ digits, beyond PHP int range), so
 * the base62 conversion is done with string arithmetic — no float/GMP needed.
 */
class ShortId
{
    protected const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** @var array<int,string>|null */
    protected static ?array $chars = null;

    /** @var array<string,int>|null */
    protected static ?array $values = null;

    /**
     * Encode subjectId + subjectType into a short token.
     *
     * @param  int|string  $subjectId
     */
    public static function encode(int|string $subjectId, int $subjectType = 0): string
    {
        // "…9456" + "02" → "…945602"; the trailing two digits are the type.
        $raw = (string) $subjectId.str_pad((string) ((int) $subjectType % 100), 2, '0', STR_PAD_LEFT);

        return self::base62($raw);
    }

    /**
     * Decode a token back to [subjectId, subjectType].
     *
     * @return array{0:string,1:int}
     */
    public static function decode(string $token): array
    {
        if (! preg_match('/^[0-9a-zA-Z]+$/', $token)) {
            return ['', 0];
        }

        $raw = self::unbase62($token);
        if (strlen($raw) < 3) {
            return ['', 0];
        }

        $type = (int) substr($raw, -2);

        return [substr($raw, 0, -2), $type];
    }

    /** Decimal string → base62 (string arithmetic, no bigint dependency). */
    protected static function base62(string $dec): string
    {
        self::init();

        $out = '';
        $num = ltrim($dec, '0');
        while ($num !== '' && $num !== '0') {
            [$num, $rem] = self::divmod62($num);
            $out = self::$chars[$rem].$out;
        }

        return $out !== '' ? $out : '0';
    }

    /** Base62 → decimal string. */
    protected static function unbase62(string $b62): string
    {
        self::init();

        $out = '0';
        $length = strlen($b62);
        for ($i = 0; $i < $length; $i++) {
            $digit = self::$values[$b62[$i]] ?? 0;
            $out = self::mulAdd62($out, 62, $digit);
        }

        return $out;
    }

    /**
     * Long division of a decimal string by 62; returns [quotient, remainder].
     *
     * @return array{0:string,1:int}
     */
    protected static function divmod62(string $dec): array
    {
        $q = '';
        $carry = 0;
        $length = strlen($dec);
        for ($i = 0; $i < $length; $i++) {
            $cur = $carry * 10 + (int) $dec[$i];
            $digit = intdiv($cur, 62);
            $carry = $cur % 62;
            if ($q !== '' || $digit !== 0) {
                $q .= (string) $digit;
            }
        }

        return [$q !== '' ? $q : '0', $carry];
    }

    /** Multiply a decimal string by $factor and add $addend (both < 62*n digits). */
    protected static function mulAdd62(string $dec, int $factor, int $addend): string
    {
        $out = '';
        $carry = $addend;
        $length = strlen($dec);
        for ($i = $length - 1; $i >= 0; $i--) {
            $cur = (int) $dec[$i] * $factor + $carry;
            $out = (string) ($cur % 10).$out;
            $carry = intdiv($cur, 10);
        }
        while ($carry > 0) {
            $out = (string) ($carry % 10).$out;
            $carry = intdiv($carry, 10);
        }

        return ltrim($out, '0') !== '' ? $out : '0';
    }

    protected static function init(): void
    {
        if (self::$chars === null) {
            self::$chars = str_split(self::ALPHABET);
            self::$values = array_flip(self::$chars);
        }
    }
}