<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

class TaxLiability extends Model
{
    protected $table = 'dian_tax_liabilities';
    protected $fillable = ['code', 'name'];
}
