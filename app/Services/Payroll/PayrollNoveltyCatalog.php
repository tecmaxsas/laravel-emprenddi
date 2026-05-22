<?php

namespace App\Services\Payroll;

/**
 * Catálogo de conceptos de novedad de nómina — devengados y deducciones
 * que se cargan manualmente por empleado en un período.
 *
 * affects_ibc: si el devengado es salarial (entra a la base de cotización
 * de salud/pensión y a la base de prestaciones sociales). Solo aplica a
 * los devengados.
 */
class PayrollNoveltyCatalog
{
    public const TYPE_EARNING = 'earning';
    public const TYPE_DEDUCTION = 'deduction';

    /**
     * @return array<string,array{name:string,type:string,affects_ibc:bool}>
     */
    public static function concepts(): array
    {
        return [
            // Devengados
            'he_diurna' => ['name' => 'Horas extra diurnas', 'type' => self::TYPE_EARNING, 'affects_ibc' => true],
            'he_nocturna' => ['name' => 'Horas extra nocturnas', 'type' => self::TYPE_EARNING, 'affects_ibc' => true],
            'he_festiva_diurna' => ['name' => 'Hora extra dominical/festiva diurna', 'type' => self::TYPE_EARNING, 'affects_ibc' => true],
            'he_festiva_nocturna' => ['name' => 'Hora extra dominical/festiva nocturna', 'type' => self::TYPE_EARNING, 'affects_ibc' => true],
            'recargo_nocturno' => ['name' => 'Recargo nocturno', 'type' => self::TYPE_EARNING, 'affects_ibc' => true],
            'recargo_festivo' => ['name' => 'Recargo dominical/festivo', 'type' => self::TYPE_EARNING, 'affects_ibc' => true],
            'comision' => ['name' => 'Comisiones', 'type' => self::TYPE_EARNING, 'affects_ibc' => true],
            'incapacidad' => ['name' => 'Incapacidad / licencia remunerada', 'type' => self::TYPE_EARNING, 'affects_ibc' => true],
            'vacaciones' => ['name' => 'Vacaciones disfrutadas', 'type' => self::TYPE_EARNING, 'affects_ibc' => true],
            'bonificacion' => ['name' => 'Bonificación (no salarial)', 'type' => self::TYPE_EARNING, 'affects_ibc' => false],
            'auxilio_extralegal' => ['name' => 'Auxilio extralegal (no salarial)', 'type' => self::TYPE_EARNING, 'affects_ibc' => false],
            'otro_devengado' => ['name' => 'Otro devengado', 'type' => self::TYPE_EARNING, 'affects_ibc' => true],

            // Deducciones
            'prestamo' => ['name' => 'Préstamo / libranza', 'type' => self::TYPE_DEDUCTION, 'affects_ibc' => false],
            'embargo' => ['name' => 'Embargo judicial', 'type' => self::TYPE_DEDUCTION, 'affects_ibc' => false],
            'retencion_fuente' => ['name' => 'Retención en la fuente', 'type' => self::TYPE_DEDUCTION, 'affects_ibc' => false],
            'aporte_voluntario' => ['name' => 'Aporte voluntario a pensión / AFC', 'type' => self::TYPE_DEDUCTION, 'affects_ibc' => false],
            'cooperativa' => ['name' => 'Aporte a cooperativa / fondo de empleados', 'type' => self::TYPE_DEDUCTION, 'affects_ibc' => false],
            'otro_descuento' => ['name' => 'Otro descuento', 'type' => self::TYPE_DEDUCTION, 'affects_ibc' => false],
        ];
    }

    public static function get(string $code): ?array
    {
        return self::concepts()[$code] ?? null;
    }

    public static function name(string $code): string
    {
        return self::concepts()[$code]['name'] ?? $code;
    }

    public static function type(string $code): string
    {
        return self::concepts()[$code]['type'] ?? self::TYPE_EARNING;
    }

    public static function affectsIbc(string $code): bool
    {
        return self::concepts()[$code]['affects_ibc'] ?? false;
    }

    /** Opciones [code => name] para selects, agrupadas por tipo. */
    public static function options(): array
    {
        $earnings = [];
        $deductions = [];
        foreach (self::concepts() as $code => $c) {
            if ($c['type'] === self::TYPE_EARNING) {
                $earnings[$code] = $c['name'];
            } else {
                $deductions[$code] = $c['name'];
            }
        }

        return [
            'Devengados' => $earnings,
            'Deducciones' => $deductions,
        ];
    }
}
