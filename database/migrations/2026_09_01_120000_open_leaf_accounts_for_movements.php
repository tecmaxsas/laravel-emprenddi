<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Habilita para movimientos las cuentas hoja que quedaron inutilizables.
 *
 * El plan de cuentas marcaba como "recibe movimientos" solo a las de 6
 * digitos. Pero el catalogo trae muchas cuentas de 4 sin subcuentas debajo:
 * quedaban 126 ramas muertas por empresa, que ni reciben movimientos ni
 * tienen un hijo que si. Entre ellas la 3705 Utilidades acumuladas, que es
 * justo la contrapartida que el sistema pide para el asiento de apertura de
 * inventario — y que su propio motor busca por codigo.
 *
 * Se postea en la cuenta mas detallada que exista, asi que una hoja de nivel
 * cuenta (4 digitos) o mas es un destino valido. Las que tienen hijos siguen
 * cerradas: ahi se postea en el hijo.
 *
 * Es aditivo: no mueve saldos ni toca asientos, solo abre cuentas que antes
 * no se podian elegir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('accounts')
            ->where('level', '>=', 3)
            ->whereNotExists(function ($q) {
                $q->from('accounts as hijos')
                    ->whereColumn('hijos.parent_id', 'accounts.id');
            })
            ->update(['accepts_movements' => true]);

        // Aqui habia un segundo paso que cerraba TODAS las cuentas con hijos
        // "por coherencia". Sobraba y rompio empresas reales: hay quien
        // habilito a mano cuentas de 4 digitos —4135, 1435— para usarlas en
        // sus productos, y al cerrarlas la importacion rechazo todas las
        // filas. Abrir hojas muertas es aditivo; cerrar lo que alguien
        // habilito a proposito no lo es. Se quito.
    }

    public function down(): void
    {
        // Volver a cerrarlas dejaria de nuevo 126 cuentas inservibles por
        // empresa, y ya podria haber asientos posteados en ellas.
    }
};
