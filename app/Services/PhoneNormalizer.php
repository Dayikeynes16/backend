<?php

namespace App\Services;

class PhoneNormalizer
{
    public static function normalize(string $input): string
    {
        $cleaned = preg_replace('/[\s\-\(\)]/', '', $input);

        if ($cleaned === null || $cleaned === '') {
            return '';
        }

        if (str_starts_with($cleaned, '+')) {
            return $cleaned;
        }

        $digitsOnly = preg_replace('/\D/', '', $cleaned);

        if (strlen($digitsOnly) === 10) {
            return '+52'.$digitsOnly;
        }

        return '+'.$digitsOnly;
    }

    public static function digits(string $e164): string
    {
        return preg_replace('/\D/', '', $e164);
    }
}
