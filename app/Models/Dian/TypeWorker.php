<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de trabajador segun la DIAN: dependiente, servicio domestico, aprendiz...
 *
 * Los ids son los de apidian y viajan en el payload: no se reasignan.
 */
class TypeWorker extends Model
{
    protected $table = 'dian_type_workers';

    protected $fillable = ['code', 'name'];
}
