<?php

declare(strict_types=1);

namespace ZeroKYC\Support;

/**
 * Decimal-string arithmetic for payment amounts. Amounts travel as strings
 * end to end; floats are never used, so no precision is ever lost.
 * Uses bcmath when available, falls back to padded string comparison.
 */
final class Money
{
    public static function isValid(string $amount): bool
    {
        return preg_match('/^\d+(\.\d+)?$/', $amount) === 1
            && self::compare($amount, '0') > 0;
    }

    /** Negative when a < b, 0 when equal, positive when a > b. */
    public static function compare(string $a, string $b): int
    {
        if (function_exists('bccomp')) {
            return bccomp($a, $b, 64);
        }

        $an = str_starts_with($a, '-');
        $bn = str_starts_with($b, '-');
        if ($an !== $bn) {
            return $an ? -1 : 1;
        }

        $cmp = self::compareUnsigned(ltrim($a, '+-'), ltrim($b, '+-'));
        return $an ? -$cmp : $cmp;
    }

    public static function greaterOrEqual(string $a, string $b): bool
    {
        return self::compare($a, $b) >= 0;
    }

    public static function format(string $amount, int $scale): string
    {
        [$int, $frac] = self::split($amount);
        if ($scale <= 0) {
            return $int;
        }
        return $int . '.' . substr(str_pad($frac, $scale, '0'), 0, $scale);
    }

    /**
     * @return array{0:string,1:string} [integer part (with sign), fraction]
     */
    private static function split(string $amount): array
    {
        $negative = str_starts_with($amount, '-');
        $clean = ltrim($amount, '+-');
        [$int, $frac] = array_pad(explode('.', $clean, 2), 2, '');
        return [$negative ? '-' . $int : $int, $frac];
    }

    private static function compareUnsigned(string $a, string $b): int
    {
        [$ai, $af] = array_pad(explode('.', $a, 2), 2, '');
        [$bi, $bf] = array_pad(explode('.', $b, 2), 2, '');
        $ai = ltrim($ai, '0') ?: '0';
        $bi = ltrim($bi, '0') ?: '0';

        if (strlen($ai) !== strlen($bi)) {
            return strlen($ai) <=> strlen($bi);
        }
        if ($ai !== $bi) {
            return $ai <=> $bi;
        }

        $scale = max(strlen($af), strlen($bf));
        $af = str_pad($af, $scale, '0');
        $bf = str_pad($bf, $scale, '0');
        return $af <=> $bf;
    }
}
