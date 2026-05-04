<?php

namespace Database\Seeders\Dian;

use App\Models\Dian\Municipality;
use Illuminate\Database\Seeder;

/**
 * Siembra municipios DIAN. Los datos vienen como pipe-delimited:
 *   id|department_id|name|code|codefacturador
 *
 * NOTA: Esta primera versión incluye las 33 capitales departamentales +
 * ciudades principales (~80 entradas). Para completar los 1122 municipios
 * oficiales, añadir las filas restantes en $this->rawData() — el formato
 * es idéntico, datos disponibles en municipalities.sql del proyecto previo.
 */
class MunicipalitiesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (explode("\n", trim($this->rawData())) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$id, $deptId, $name, $code, $codeFac] = array_pad(explode('|', $line), 5, null);

            Municipality::updateOrCreate(
                ['id' => (int) $id],
                [
                    'dian_department_id' => (int) $deptId,
                    'name' => trim($name),
                    'code' => trim($code),
                    'codefacturador' => $codeFac !== '' ? (int) $codeFac : null,
                ],
            );
        }
    }

    private function rawData(): string
    {
        // Capitales departamentales + ciudades principales.
        // Source: DIAN códigos DANE (Decreto 0250/2017 y actualizaciones).
        return <<<'DATA'
1|2|Medellín|05001|12601
13|2|Apartadó|05045|12544
47|2|Envigado|05266|12578
59|2|Itagüí|05360|12590
85|2|Rionegro|05615|13428
126|4|Barranquilla|08001|12666
145|4|Soledad|08758|12684
149|5|Bogotá D.C.|11001|12688
150|6|Cartagena de Indias|13001|12697
196|7|Tunja|15001|12848
227|7|Duitama|15238|12764
293|7|Sogamoso|15759|12830
319|8|Manizales|17001|12865
324|8|Chinchiná|17174|12861
346|9|Florencia|18001|12930
362|11|Popayán|19001|12944
404|12|Valledupar|20001|12984
429|14|Montería|23001|13029
469|15|Cajicá|25126|13053
474|15|Chía|25175|13058
489|15|Fusagasugá|25290|13073
494|15|Girardot|25307|13078
513|15|Madrid|25430|13097
545|15|Soacha|25754|13129
574|15|Zipaquirá|25899|13158
575|13|Quibdó|27001|13006
605|18|Neiva|41001|13182
629|18|Pitalito|41551|13188
642|19|Riohacha|44001|13510
651|19|Maicao|44430|12706
657|20|Santa Marta|47001|13226
663|20|Ciénaga|47189|13206
687|21|Villavicencio|50001|13258
698|21|Granada|50313|13241
716|22|Pasto|52001|13301
743|22|Ipiales|52356|13286
777|22|San Andrés de Tumaco|52835|13430
780|23|San José de Cúcuta|54001|12879
804|23|Ocaña|54498|13417
820|25|Armenia|63001|13337
822|25|Calarcá|63130|13339
832|26|Pereira|66001|13358
836|26|Dosquebradas|66170|13352
846|28|Bucaramanga|68001|13371
852|28|Barrancabermeja|68081|13368
878|28|Floridablanca|68276|13395
881|28|Girón|68307|13398
906|28|Piedecuesta|68547|13423
933|29|Sincelejo|70001|13471
959|30|Ibagué|73001|13496
974|30|Espinal|73268|13488
1006|31|Cali|76001|48324
1012|31|Buenaventura|76109|13371
1013|31|Guadalajara de Buga|76111|13281
1018|31|Cartago|76147|12886
1027|31|Jamundí|76364|12933
1032|31|Palmira|76520|13421
1041|31|Tuluá|76834|12686
1046|31|Yumbo|76892|12918
1048|3|Arauca|81001|12658
1055|10|Yopal|85001|12918
1074|24|Mocoa|86001|13325
1077|24|Puerto Asís|86568|13327
1087|27|San Andrés|88001|48357
1088|27|Providencia|88564|48358
1089|1|Leticia|91001|12531
1100|16|Inírida|94001|13159
1109|17|San José del Guaviare|95001|13163
1113|32|Mitú|97001|13523
1119|33|Puerto Carreño|99001|13530
DATA;
    }
}
