<?php

namespace Database\Seeders\Dian;

use App\Models\Dian\RegimeType;
use Illuminate\Database\Seeder;

class RegimeTypesSeeder extends Seeder
{
    public function run(): void
    {
        RegimeType::updateOrCreate(['id' => 1], ['code' => '48', 'name' => 'Responsable de IVA']);
        RegimeType::updateOrCreate(['id' => 2], ['code' => '49', 'name' => 'No Responsable de IVA']);
    }
}
