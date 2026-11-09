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

    public static function key(?string $value): ?string
    {
        $formatted = self::format($value);
        if (! preg_match('/^[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{5}$/D', $formatted)) {
            return null;
        }
        $digits = str_replace('-', '', $formatted);

        return substr($digits, 0, 9) === '000000000' ? null : $digits;
    }

    /** @return list<string> */
    public static function equivalentDigits(?string $value): array
    {
        $key = self::key($value);
        if ($key === null) {
            return [];
        }
        $taxpayer = substr($key, 0, 9);
        $branch = ltrim(substr($key, 9), '0');
        $variants = [];
        foreach ([3, 4, 5] as $width) {
            $variants[] = $taxpayer.str_pad($branch, $width, '0', STR_PAD_LEFT);
        }
        if ($branch === '') {
            $variants[] = $taxpayer;
        }

        return array_values(array_unique($variants));
    }
}
