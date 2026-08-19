<?php

namespace App\Services\CyberSource;

class CyberSourceSanitizer
{
    public static function maskPan(string $pan): string
    {
        $digits = preg_replace('/\D+/', '', $pan) ?? '';

        if ($digits === '') {
            return '****';
        }

        $last4 = substr($digits, -4);
        return str_repeat('*', max(0, strlen($digits) - 4)) . $last4;
    }

    public static function maskCvv(string $cvv): string
    {
        $value = preg_replace('/\D+/', '', $cvv) ?? '';

        if ($value === '') {
            return '***';
        }

        return str_repeat('*', min(strlen($value), 3));
    }

    public static function redactSensitiveValue(string $value): string
    {
        if ($value === '') {
            return '***REMOVED***';
        }

        return '***REMOVED***';
    }
}
