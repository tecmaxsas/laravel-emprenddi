<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

    public function test_una_cuenta_con_hijos_nunca_recibe_movimientos_directos(): void
    {
        $abiertasPorError = $this->cuentas()
            ->where('accepts_movements', true)
            ->whereIn('id', $this->idsConHijos())
            ->orderBy('code')
            ->get(['code', 'name']);

        $this->assertCount(
            0,
            $abiertasPorError,
            'Se postea en el hijo, no en el padre: '
            .$abiertasPorError->take(10)->map(fn ($a) => $a->code.' '.$a->name)->implode(', ')
        );
    }

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
}
