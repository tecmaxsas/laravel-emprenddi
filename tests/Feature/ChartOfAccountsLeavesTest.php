<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El plan de cuentas no puede tener ramas muertas.
 *
 * La regla era "solo las de 6 digitos reciben movimientos", pero el catalogo
 * trae muchas cuentas de 4 sin subcuentas debajo: quedaban 126 por empresa
 * que no se podian usar ni tenian hijo que si. Entre ellas la 3705, que es la
 * contrapartida que el sistema pide para el asiento de apertura de inventario
 * y que su propio motor busca por codigo.
 */
class ChartOfAccountsLeavesTest extends TestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (int) User::query()->whereNotNull('company_id')->firstOrFail()->company_id;
    }

    /** @return Builder<Account> */
    private function cuentas()
    {
        return Account::withoutGlobalScopes()->where('company_id', $this->companyId);
    }

    private function idsConHijos(): array
    {
        return $this->cuentas()
            ->whereNotNull('parent_id')
            ->distinct()
            ->pluck('parent_id')
            ->all();
    }

    public function test_ninguna_cuenta_hoja_queda_sin_poder_recibir_movimientos(): void
    {
        $muertas = $this->cuentas()
            ->where('level', '>=', 3)
            ->where('accepts_movements', false)
            ->whereNotIn('id', $this->idsConHijos())
            ->orderBy('code')
            ->get(['code', 'name']);

        $this->assertCount(
            0,
            $muertas,
            'Cuentas sin hijos que tampoco reciben movimientos: '
            .$muertas->take(10)->map(fn ($a) => $a->code.' '.$a->name)->implode(', ')
        );
    }

    /**
     * NO se comprueba que una cuenta con hijos este cerrada.
     *
     * Esa era la regla que rompio empresas reales: hay quien habilita a mano
     * una cuenta de 4 digitos —4135 como cuenta de venta— y la usa en sus
     * productos aunque tenga subcuentas debajo. Es una decision contable
     * legitima que el sistema no debe deshacer por su cuenta.
     */
    /** La contrapartida que el sistema busca por codigo tiene que ser usable. */
    public function test_la_3705_sirve_como_contrapartida_del_inventario_inicial(): void
    {
        $cuenta = $this->cuentas()->where('code', '3705')->first();

        if (! $cuenta) {
            $this->markTestSkipped('La empresa de dev no tiene la 3705 en su plan.');
        }

        $this->assertTrue((bool) $cuenta->accepts_movements,
            'InventoryEngine busca la 3705 para el asiento de apertura.');
        $this->assertTrue((bool) $cuenta->active);
    }

    /**
     * Una cuenta que ya esta EN USO tiene que poder recibir movimientos,
     * tenga hijos o no.
     *
     * Hay quien habilita a mano cuentas de 4 digitos —4135 como cuenta de
     * venta, 1435 como inventario— y las asigna a sus productos. Cerrarlas
     * "por coherencia" deja esos productos apuntando a una cuenta inservible
     * y la importacion rechaza todas las filas. Ya paso una vez.
     */
    public function test_ninguna_cuenta_en_uso_queda_cerrada(): void
    {
        $referencias = [
            ['products', 'sale_account_id'],
            ['products', 'inventory_account_id'],
            ['products', 'cost_account_id'],
            ['taxes', 'sale_account_id'],
            ['taxes', 'purchase_account_id'],
            ['payment_methods', 'account_id'],
            ['journal_entry_lines', 'account_id'],
        ];

        $enUso = [];
        foreach ($referencias as [$tabla, $columna]) {
            $enUso = array_merge($enUso, DB::table($tabla)
                ->whereNotNull($columna)->distinct()->pluck($columna)->all());
        }

        $enUso = array_values(array_unique(array_map('intval', $enUso)));

        if ($enUso === []) {
            $this->markTestSkipped('La empresa de dev no tiene cuentas asignadas todavía.');
        }

        $cerradas = Account::withoutGlobalScopes()
            ->whereIn('id', $enUso)
            ->where('accepts_movements', false)
            ->orderBy('code')
            ->get(['code', 'name']);

        $this->assertCount(
            0,
            $cerradas,
            'Cuentas en uso que no reciben movimientos: '
            .$cerradas->take(10)->map(fn ($a) => $a->code.' '.$a->name)->implode(', ')
        );
    }
}
