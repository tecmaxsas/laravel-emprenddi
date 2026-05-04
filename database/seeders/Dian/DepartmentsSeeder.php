<?php

namespace Database\Seeders\Dian;

use App\Models\Dian\Department;
use Illuminate\Database\Seeder;

class DepartmentsSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['id' => 1, 'code' => '91', 'name' => 'Amazonas'],
            ['id' => 2, 'code' => '05', 'name' => 'Antioquia'],
            ['id' => 3, 'code' => '81', 'name' => 'Arauca'],
            ['id' => 4, 'code' => '08', 'name' => 'Atlántico'],
            ['id' => 5, 'code' => '11', 'name' => 'Bogotá D.C.'],
            ['id' => 6, 'code' => '13', 'name' => 'Bolívar'],
            ['id' => 7, 'code' => '15', 'name' => 'Boyacá'],
            ['id' => 8, 'code' => '17', 'name' => 'Caldas'],
            ['id' => 9, 'code' => '18', 'name' => 'Caquetá'],
            ['id' => 10, 'code' => '85', 'name' => 'Casanare'],
            ['id' => 11, 'code' => '19', 'name' => 'Cauca'],
            ['id' => 12, 'code' => '20', 'name' => 'Cesar'],
            ['id' => 13, 'code' => '27', 'name' => 'Chocó'],
            ['id' => 14, 'code' => '23', 'name' => 'Córdoba'],
            ['id' => 15, 'code' => '25', 'name' => 'Cundinamarca'],
            ['id' => 16, 'code' => '94', 'name' => 'Guainía'],
            ['id' => 17, 'code' => '95', 'name' => 'Guaviare'],
            ['id' => 18, 'code' => '41', 'name' => 'Huila'],
            ['id' => 19, 'code' => '44', 'name' => 'La Guajira'],
            ['id' => 20, 'code' => '47', 'name' => 'Magdalena'],
            ['id' => 21, 'code' => '50', 'name' => 'Meta'],
            ['id' => 22, 'code' => '52', 'name' => 'Nariño'],
            ['id' => 23, 'code' => '54', 'name' => 'Norte de Santander'],
            ['id' => 24, 'code' => '86', 'name' => 'Putumayo'],
            ['id' => 25, 'code' => '63', 'name' => 'Quindío'],
            ['id' => 26, 'code' => '66', 'name' => 'Risaralda'],
            ['id' => 27, 'code' => '88', 'name' => 'San Andrés y Providencia'],
            ['id' => 28, 'code' => '68', 'name' => 'Santander'],
            ['id' => 29, 'code' => '70', 'name' => 'Sucre'],
            ['id' => 30, 'code' => '73', 'name' => 'Tolima'],
            ['id' => 31, 'code' => '76', 'name' => 'Valle del Cauca'],
            ['id' => 32, 'code' => '97', 'name' => 'Vaupés'],
            ['id' => 33, 'code' => '99', 'name' => 'Vichada'],
        ];

        foreach ($departments as $d) {
            Department::updateOrCreate(['id' => $d['id']], $d);
        }
    }
}
