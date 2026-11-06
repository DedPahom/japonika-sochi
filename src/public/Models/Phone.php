<?php
declare(strict_types=1);

/** Canonical phone representation for this Russia-based shop: +7 followed by 10 digits. */
final class Phone
{
    public static function normalizeRussian(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || !preg_match('/^[\s\p{Z}0-9()+.\-]+$/u', $input)) return null;
        $digits = preg_replace('/\D+/', '', $input);
        if (!is_string($digits)) return null;
        if (strlen($digits) === 10) $digits = '7' . $digits;
        elseif (strlen($digits) === 11 && $digits[0] === '8') $digits[0] = '7';
        elseif (strlen($digits) !== 11 || $digits[0] !== '7') return null;
        return '+' . $digits;
    }
}
