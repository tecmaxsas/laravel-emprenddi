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

    /**
     * ¿El digito de verificacion tiene valor?
     *
     * No se puede preguntar con `! $dv`: el DV "0" es perfectamente valido y
     * en PHP el string "0" es falsy, asi que una empresa con DV 0 quedaba
     * marcada como "sin DV". Eso bloqueaba el registro ante la DIAN, borraba
     * el guion del NIT en los documentos y omitia el dv del payload que se le
     * envia a la DIAN.
     *
     * 'NULL' como texto aparece en datos importados de otros sistemas.
     */
    public static function hasValue(mixed $dv): bool
    {
        if ($dv === null) {
            return false;
        }

        $dv = trim((string) $dv);

        return $dv !== '' && strtoupper($dv) !== 'NULL';
    }
}
