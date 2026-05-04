<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

class RegimeType extends Model
{
    protected $table = 'dian_regime_types';
    protected $fillable = ['code', 'name'];
}
