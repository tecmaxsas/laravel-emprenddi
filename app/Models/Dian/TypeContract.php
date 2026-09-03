<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de contrato laboral segun la DIAN.
 *
 * Los ids son los de apidian y viajan en el payload: no se reasignan.
 */
class TypeContract extends Model
{
    protected $table = 'dian_type_contracts';

    protected $fillable = ['code', 'name'];
}
