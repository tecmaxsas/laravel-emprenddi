<?php

namespace App\Services\Payroll;

/**
 * Catálogo de "casillas" contables de la nómina: cada una se mapea a una
 * cuenta del PUC de la empresa. El motor de contabilización arma el
 * asiento de la nómina con base en este mapeo.
 *
 * group: 'expense' = cuenta de gasto (débito), 'payable' = pasivo (crédito).
 */
class PayrollAccountSlots
{
    public static function slots(): array
    {
        return [
            'expense_salary' => ['name' => 'Salarios', 'group' => 'expense'],
            'expense_transport' => ['name' => 'Auxilio de transporte', 'group' => 'expense'],
            'expense_overtime' => ['name' => 'Horas extra y otros devengados', 'group' => 'expense'],
            'expense_employer_contributions' => ['name' => 'Aportes patronales (salud, pensión, ARL, parafiscales)', 'group' => 'expense'],
            'expense_provisions' => ['name' => 'Provisión de prestaciones sociales', 'group' => 'expense'],
            'expense_severance' => ['name' => 'Indemnizaciones laborales', 'group' => 'expense'],

            'payable_net_salary' => ['name' => 'Salarios por pagar (neto)', 'group' => 'payable'],
            'payable_health' => ['name' => 'Aportes a salud por pagar', 'group' => 'payable'],
            'payable_pension' => ['name' => 'Aportes a pensión y fondo de solidaridad por pagar', 'group' => 'payable'],
            'payable_arl' => ['name' => 'Aportes a ARL por pagar', 'group' => 'payable'],
            'payable_parafiscal' => ['name' => 'Parafiscales por pagar (caja, SENA, ICBF)', 'group' => 'payable'],
            'payable_withholding' => ['name' => 'Retención en la fuente por pagar', 'group' => 'payable'],
            'payable_other_deductions' => ['name' => 'Otras deducciones por pagar (préstamos, embargos)', 'group' => 'payable'],
            'payable_cesantias' => ['name' => 'Cesantías por pagar', 'group' => 'payable'],
            'payable_interest' => ['name' => 'Intereses sobre cesantías por pagar', 'group' => 'payable'],
            'payable_prima' => ['name' => 'Prima de servicios por pagar', 'group' => 'payable'],
            'payable_vacaciones' => ['name' => 'Vacaciones por pagar', 'group' => 'payable'],
        ];
    }

    public static function codes(): array
    {
        return array_keys(self::slots());
    }

    public static function name(string $slot): string
    {
        return self::slots()[$slot]['name'] ?? $slot;
    }

    /** @return array<string,array{name:string,group:string}> */
    public static function expenseSlots(): array
    {
        return array_filter(self::slots(), fn ($s) => $s['group'] === 'expense');
    }

    /** @return array<string,array{name:string,group:string}> */
    public static function payableSlots(): array
    {
        return array_filter(self::slots(), fn ($s) => $s['group'] === 'payable');
    }
}
