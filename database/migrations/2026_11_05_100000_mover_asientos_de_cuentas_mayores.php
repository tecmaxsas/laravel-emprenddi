<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mueve a su cuenta hija los asientos que quedaron contra una cuenta MAYOR.
 *
 * QUE PASO.
 *
 * Veintiseis sitios resolvian la cuenta por codigo sin exigir que aceptara
 * movimientos: `->where('code', '1435')->value('id')`. En un plan donde ese
 * codigo es una hoja, funciona. Pero `1435` es una cuenta mayor —debajo tiene
 * `143505 Inventario general`— y en los planes sembrados completos el asiento
 * se fue contra la mayor.
 *
 * Contabilizar contra una mayor no es un detalle de forma: el informe por
 * niveles suma el movimiento en la mayor Y en el total de sus hijas, asi que
 * cuenta dos veces. El propio sistema ya lo prohibia con `accepts_movements`,
 * pero nadie le preguntaba.
 *
 * QUE HACE ESTA MIGRACION.
 *
 * Reapunta esas lineas a la primera hija que si recibe movimientos, con el
 * mismo criterio que `DefaultAccounts::porCodigo` usa de ahora en adelante.
 *
 * NO toca importes, ni asientos, ni documentos: solo la cuenta de la linea. El
 * asiento sigue cuadrando porque debitos y creditos no cambian. Y no borra
 * nada: un asiento borrado se lleva por delante la factura o el pago que lo
 * referencia, y aqui hay pagos que los referencian.
 *
 * Si una mayor no tiene ninguna hija que acepte movimientos, la linea se deja
 * como esta y se reporta: inventarle una cuenta seria peor que dejar el
 * problema a la vista.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mayoresEnUso = DB::table('journal_entry_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('accounts.accepts_movements', false)
            ->distinct()
            ->pluck('accounts.id');

        $movidas = 0;
        $sinHija = [];

        foreach ($mayoresEnUso as $cuentaId) {
            $mayor = Account::withoutGlobalScopes()->find($cuentaId);

            if (! $mayor) {
                continue;
            }

            $hija = Account::withoutGlobalScopes()
                ->where('company_id', $mayor->company_id)
                ->where('code', 'like', $mayor->code.'%')
                ->where('code', '!=', $mayor->code)
                ->where('accepts_movements', true)
                ->where('active', true)
                ->orderBy('code')
                ->first(['id', 'code']);

            if (! $hija) {
                $sinHija[] = $mayor->code.' '.$mayor->name;

                continue;
            }

            $movidas += DB::table('journal_entry_lines')
                ->where('account_id', $mayor->id)
                ->update(['account_id' => $hija->id]);
        }

        echo "   Lineas movidas a su cuenta hija: {$movidas}\n";

        if ($sinHija !== []) {
            echo '   OJO, cuentas mayores sin hija que acepte movimientos: '
                .implode(', ', $sinHija)."\n";
        }
    }

    /**
     * No se devuelve.
     *
     * Volver a poner el movimiento en una cuenta que no lo acepta seria
     * restaurar el error, y nada de lo que se arreglo aqui dependia de que
     * estuviera mal.
     */
    public function down(): void {}
};
