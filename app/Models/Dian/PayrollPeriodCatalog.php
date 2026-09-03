<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

/**
 * Periodicidad de pago de la nomina electronica: semanal, quincenal, mensual.
 *
 * Se llama Catalog para no confundirla con PayrollPeriod, que es el periodo
 * que la empresa liquida.
 *
 * Los ids son los de apidian y viajan en el payload: no se reasignan.
 */
class PayrollPeriodCatalog extends Model
{
    protected $table = 'dian_payroll_periods';

    protected $fillable = ['code', 'name'];
}
