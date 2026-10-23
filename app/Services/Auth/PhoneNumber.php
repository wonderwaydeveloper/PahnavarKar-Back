<?php

namespace App\Services\Auth;

class PhoneNumber
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/\D/', '', $digits) ?? '';

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '98')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^9\d{9}$/', $digits) === 1 ? '+98'.$digits : null;
    }

    public static function forRateLimit(mixed $value): string
    {
        $normalized = self::normalize($value);
        $raw = is_string($value) || is_numeric($value) ? trim((string) $value) : '';

        return hash('sha256', $normalized ?? $raw);
    }
}
