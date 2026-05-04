<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $table = 'dian_payment_methods';
    protected $fillable = ['code', 'name'];
}
