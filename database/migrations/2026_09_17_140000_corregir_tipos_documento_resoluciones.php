<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pone los códigos de la DIAN en `dian_resolutions.document_type_id`.
 *
 * Ese campo viaja tal cual al proveedor tecnológico al registrar la resolución,
 * y cada documento se transmite con el código DIAN que le corresponde. Pero el
 * formulario guardaba una numeración propia (1..6) en la que solo el 1 coincidía
 * con la DIAN por casualidad.
 *
 * Por eso las facturas funcionaban y las notas crédito no: la resolución de
 * notas quedaba registrada allá como tipo 2 —que para la DIAN es Factura de
 * Exportación— y la nota se enviaba declarando su tipo 4. El proveedor no
 * encontraba resolución y respondía «La resolución no está configurada», con el
 * rango vacío. Nada en ese mensaje apuntaba a un desajuste de catálogos.
 *
 *   Antes (nuestro)          Ahora (DIAN)
 *   1  Factura Electrónica → 1   (ya coincidía)
 *   2  Nota Crédito        → 4
 *   3  Nota Débito         → 5
 *   4  Documento Soporte   → 11
 *   5  Nómina Electrónica  → 9
 *   6  Factura Exportación → 2
 *
 * **Esto arregla la base, no el proveedor.** Allá la resolución sigue registrada
 * bajo el tipo equivocado hasta que se vuelva a guardar desde Configuración →
 * DIAN, que es lo que la reenvía. Esta migración deja el aviso en el log.
 */
return new class extends Migration
{
    /** viejo => nuevo */
    private const MAPA = [
        2 => 4,
        3 => 5,
        4 => 11,
        5 => 9,
        6 => 2,
    ];

    public function up(): void
    {
        $this->remapear(self::MAPA);
    }

    public function down(): void
    {
        $this->remapear(array_flip(self::MAPA));
    }

    /**
     * Se hace con un solo CASE y no con un UPDATE por tipo a propósito: en
     * cadena, el 2→4 y el 6→2 se pisarían y una resolución terminaría con el
     * tipo de otra.
     *
     * @param  array<int, int>  $mapa
     */
    private function remapear(array $mapa): void
    {
        $afectadas = DB::table('dian_resolutions')
            ->whereIn('document_type_id', array_keys($mapa))
            ->count();

        if ($afectadas === 0) {
            return;
        }

        $casos = '';
        foreach ($mapa as $viejo => $nuevo) {
            $casos .= " when {$viejo} then {$nuevo}";
        }

        $lista = implode(',', array_keys($mapa));

        DB::statement("
            update dian_resolutions
            set document_type_id = case document_type_id{$casos} else document_type_id end
            where document_type_id in ({$lista})
        ");

        // El nombre guardado se escribió con la lista vieja, así que quedaría
        // diciendo «Documento Soporte» sobre una nota crédito.
        foreach ([1 => 'Factura Electrónica', 2 => 'Factura de Exportación', 4 => 'Nota Crédito',
            5 => 'Nota Débito', 9 => 'Nómina Electrónica', 11 => 'Documento Soporte'] as $id => $nombre) {
            DB::table('dian_resolutions')
                ->where('document_type_id', $id)
                ->update(['document_type_name' => $nombre]);
        }
    }
};
