<?php

namespace Jelite;

class Phone
{
    public static function toE164(string $input, string $defaultCountryCode = '63'): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $hasPlus = str_starts_with($input, '+');
        $digits = preg_replace('/\D+/', '', $input);
        if ($digits === null || $digits === '' || !ctype_digit($digits)) {
            return null;
        }

        if ($hasPlus) {
            return self::validate($digits) ? '+' . $digits : null;
        }

        $cc = preg_replace('/\D+/', '', $defaultCountryCode) ?: '63';

        // Local trunk format: leading 0 → country code.
        if (str_starts_with($digits, '0')) {
            $digits = $cc . substr($digits, 1);
            return self::validate($digits) ? '+' . $digits : null;
        }

        // Already includes country code.
        if (str_starts_with($digits, $cc)) {
            return self::validate($digits) ? '+' . $digits : null;
        }

        // Bare subscriber number (e.g. 9171234567).
        $digits = $cc . $digits;
        return self::validate($digits) ? '+' . $digits : null;
    }

    private static function validate(string $digits): bool
    {
        return strlen($digits) >= 8 && strlen($digits) <= 15;
    }
}
