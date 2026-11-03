<?php

namespace App\Services;

use Illuminate\Support\Str;

class BirDatText
{
    public static function clean(?string $value): string
    {
        $text = strtoupper(Str::ascii($value ?? ''));
        if (! preg_match('/[A-Z0-9]/', $text)) {
            return '';
        }

        $text = str_replace('&', ' AND ', $text);
        $text = str_replace("'", '', $text);
        $text = preg_replace('/[^A-Z0-9 ]/', ' ', $text);

        return trim(preg_replace('/ +/', ' ', $text));
    }
}
