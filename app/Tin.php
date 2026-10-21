<?php

namespace App;

class Tin
{
    public static function format(?string $value, ?string $branch = null): string
    {
        $value = trim($value ?? '');
        $digits = preg_replace('/\D/', '', $value);
        if (! preg_match('/^(?:[0-9]{9}|[0-9]{12,14}|[0-9]{3}-[0-9]{3}-[0-9]{3}(?:-[0-9]{3,5})?)$/', $value) || ! in_array(strlen($digits), [9, 12, 13, 14], true)) {
            return $value;
        }
        $branch = $branch ?? substr($digits, 9);
        $branch = str_pad($branch, 5, '0', STR_PAD_LEFT);

        return substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3).'-'.$branch;
    }
}
