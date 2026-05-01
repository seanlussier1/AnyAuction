<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Normalize US/Canada (NANP) phone numbers to E.164.
 *
 * Accepts common formatting: "(555) 123-4567", "555-123-4567",
 * "+1 555 123 4567", "15551234567", etc. Strips everything but digits,
 * drops a leading "1" if present, and requires exactly 10 remaining
 * digits. Returns "+1XXXXXXXXXX" or null on invalid input.
 */
final class PhoneNormalizer
{
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if ($digits === '') {
            return null;
        }

        // Drop a single leading country code "1" if 11 digits.
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        // NANP area codes don't start with 0 or 1.
        if ($digits[0] === '0' || $digits[0] === '1') {
            return null;
        }

        return '+1' . $digits;
    }
}
