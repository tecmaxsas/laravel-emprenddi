<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

/**
 * Subtipo de trabajador. Lo normal es 'No aplica'; los demas cubren casos de
 * pensionados y regimenes especiales.
 *
 * Los ids son los de apidian y viajan en el payload: no se reasignan.
 */
class SubTypeWorker extends Model
{
    protected $table = 'dian_sub_type_workers';

    protected $fillable = ['code', 'name'];
}
