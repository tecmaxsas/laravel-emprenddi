<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

/**
 * Deducciones de ley: salud, pension, fondo de solidaridad.
 *
 * La tarifa distingue conceptos que se llaman parecido — la pension de alto
 * riesgo del trabajador es un concepto distinto al de la pension normal.
 *
 * Los ids son los de apidian y viajan en el payload: no se reasignan.
 */
class TypeLawDeduction extends Model
{
    protected $table = 'dian_type_law_deductions';

    protected $fillable = ['code', 'name', 'percentage'];
}
