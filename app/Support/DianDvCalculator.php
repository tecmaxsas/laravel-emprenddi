<?php

namespace App\Support;

class DianDvCalculator
{
    private const COEFFICIENTS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    public static function calculate(string $documentNumber): ?int
    {
        $digits = preg_replace('/\D/', '', $documentNumber);

        if ($digits === '' || strlen($digits) > 15) {
            return null;
        }

        $digitsReversed = array_reverse(str_split($digits));
        $sum = 0;

        foreach ($digitsReversed as $i => $digit) {
            $sum += (int) $digit * self::COEFFICIENTS[$i];
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? $remainder : 11 - $remainder;
    }
}
