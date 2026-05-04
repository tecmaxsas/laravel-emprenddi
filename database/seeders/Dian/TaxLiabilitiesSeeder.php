<?php

namespace Database\Seeders\Dian;

use App\Models\Dian\TaxLiability;
use Illuminate\Database\Seeder;

class TaxLiabilitiesSeeder extends Seeder
{
    public function run(): void
    {
        $liabilities = [
            ['id' => 7, 'code' => 'O-13', 'name' => 'Gran contribuyente'],
            ['id' => 9, 'code' => 'O-15', 'name' => 'Autorretenedor'],
            ['id' => 14, 'code' => 'O-23', 'name' => 'Agente de retención en el impuesto sobre las ventas'],
            ['id' => 112, 'code' => 'O-47', 'name' => 'Régimen Simple de Tributación – SIMPLE'],
            ['id' => 117, 'code' => 'R-99-PN', 'name' => 'No responsable'],
        ];

        foreach ($liabilities as $l) {
            TaxLiability::updateOrCreate(['id' => $l['id']], $l);
        }
    }
}
