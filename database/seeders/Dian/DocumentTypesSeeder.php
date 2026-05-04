<?php

namespace Database\Seeders\Dian;

use App\Models\Dian\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypesSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['id' => 1, 'code' => '11', 'name' => 'Registro civil'],
            ['id' => 2, 'code' => '12', 'name' => 'Tarjeta de identidad'],
            ['id' => 3, 'code' => '13', 'name' => 'Cédula de ciudadanía'],
            ['id' => 4, 'code' => '21', 'name' => 'Tarjeta de extranjería'],
            ['id' => 5, 'code' => '22', 'name' => 'Cédula de extranjería'],
            ['id' => 6, 'code' => '31', 'name' => 'NIT'],
            ['id' => 7, 'code' => '41', 'name' => 'Pasaporte'],
            ['id' => 8, 'code' => '42', 'name' => 'Documento de identificación extranjero'],
            ['id' => 9, 'code' => '50', 'name' => 'NIT de otro país'],
            ['id' => 10, 'code' => '91', 'name' => 'NUIP'],
            ['id' => 11, 'code' => '47', 'name' => 'PEP'],
        ];

        foreach ($types as $t) {
            DocumentType::updateOrCreate(['id' => $t['id']], $t);
        }
    }
}
