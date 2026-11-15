<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use StoreKeeper\Commerce\Core\Exception\InvalidPhone;

/**
 * Phone normalisation to E.164 (§7.3).
 *
 * Accepts the forms Nigerian merchants actually receive: "0801 234 5678",
 * "2348012345678", "+234 801 234 5678", "0801-234-5678". Anything already in
 * international form keeps its country code; a leading 0 (or a bare 10-digit
 * number) is treated as Nigerian.
 */
final class Phone
{
    private const NIGERIA_CODE = '234';

    private const NIGERIA_NATIONAL_LENGTH = 10;

    /** Normalise, or throw InvalidPhone. */
    public static function normalise(string $input): string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            throw new InvalidPhone('A phone number is required.');
        }

        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            throw new InvalidPhone(sprintf('"%s" is not a phone number.', $input));
        }

        // International access prefix: 00 234 …
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($hasPlus || strlen($digits) > 11) {
            return self::toE164($digits, $input);
        }

        if (str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } elseif (str_starts_with($digits, self::NIGERIA_CODE) && strlen($digits) === 13) {
            $national = substr($digits, 3);
        } else {
            $national = $digits;
        }

        return self::toE164(self::NIGERIA_CODE . $national, $input);
    }

    /** True when the value is already a valid E.164 number we accept. */
    public static function isValid(string $input): bool
    {
        try {
            self::normalise($input);

            return true;
        } catch (InvalidPhone) {
            return false;
        }
    }

    /** Last-resort display form: "+234 801 234 5678". */
    public static function display(string $e164): string
    {
        $normalised = self::normalise($e164);
        $national = substr($normalised, 4);

        return sprintf(
            '+%s %s %s %s',
            self::NIGERIA_CODE,
            substr($national, 0, 3),
            substr($national, 3, 3),
            substr($national, 6)
        );
    }

    /** The local form used in WhatsApp links and for matching: "08012345678". */
    public static function local(string $e164): string
    {
        $normalised = self::normalise($e164);
        if (str_starts_with($normalised, '+' . self::NIGERIA_CODE)) {
            return '0' . substr($normalised, 4);
        }

        return $normalised;
    }

    private static function toE164(string $digits, string $original): string
    {
        if (str_starts_with($digits, self::NIGERIA_CODE)) {
            $national = substr($digits, 3);
            if (strlen($national) === self::NIGERIA_NATIONAL_LENGTH && preg_match('/^[789]\d{9}$/', $national) === 1) {
                return '+' . self::NIGERIA_CODE . $national;
            }

            throw new InvalidPhone(sprintf('"%s" is not a valid Nigerian phone number.', $original));
        }

        // Other countries: accept a plausible E.164 length (8–15 digits, non-zero lead).
        if (strlen($digits) >= 8 && strlen($digits) <= 15 && $digits[0] !== '0') {
            return '+' . $digits;
        }

        throw new InvalidPhone(sprintf('"%s" is not a valid phone number.', $original));
    }
}
