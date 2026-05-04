<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

class PaymentForm extends Model
{
    protected $table = 'dian_payment_forms';
    protected $fillable = ['code', 'name'];
}
