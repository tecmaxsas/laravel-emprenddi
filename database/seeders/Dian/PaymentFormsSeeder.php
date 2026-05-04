<?php

namespace Database\Seeders\Dian;

use App\Models\Dian\PaymentForm;
use Illuminate\Database\Seeder;

class PaymentFormsSeeder extends Seeder
{
    public function run(): void
    {
        PaymentForm::updateOrCreate(['id' => 1], ['code' => '1', 'name' => 'Contado']);
        PaymentForm::updateOrCreate(['id' => 2], ['code' => '2', 'name' => 'Crédito']);
    }
}
