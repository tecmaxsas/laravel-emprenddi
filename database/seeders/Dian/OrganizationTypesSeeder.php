<?php

namespace Database\Seeders\Dian;

use App\Models\Dian\OrganizationType;
use Illuminate\Database\Seeder;

class OrganizationTypesSeeder extends Seeder
{
    public function run(): void
    {
        OrganizationType::updateOrCreate(['id' => 1], ['code' => '1', 'name' => 'Persona Jurídica']);
        OrganizationType::updateOrCreate(['id' => 2], ['code' => '2', 'name' => 'Persona Natural']);
    }
}
