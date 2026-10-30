<?php

namespace Tests\Feature;

use App\Filament\App\Pages\PosTerminal;
use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\User;
use App\Services\Auth\PermissionsCatalog;
use App\Services\Cash\CashMovementEngine;
use App\Services\Cash\CashSessionSummary;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\FuerzaPosDeRetail;
use Tests\TestCase;

/**
 * Entradas y salidas directas de la caja.
 *
 * La plata del cajón se mueve por más razones que las ventas: hay que pagarle
 * al domiciliario, sacar lo acumulado a la caja fuerte, meter cambio. Hasta
 * ahora nada de eso tenía dónde registrarse, así que el cajero cuadraba «a
 * mano» y la diferencia aparecía al cerrar como faltante.
 *
 * **Un faltante que en realidad fue el pago del domicilio es peor que un
 * faltante de verdad**: le enseña a todo el mundo que las diferencias de caja
 * no significan nada, y a partir de ahí nadie vuelve a mirar un arqueo.
 *
 * Lo que estas pruebas fijan son las tres reglas que sostienen la función:
 * que llegue al arqueo, que no se pueda sacar lo que no hay, y que siempre
 * quede asiento.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CashMovementTest extends TestCase
{
    use FuerzaPosDeRetail;

    private Company $empresa;

    private User $cajero;

    private Location $sede;

    private CashRegisterSession $turno;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($this->cajero->company_id);

        $this->actingAs($this->cajero);
        app(CurrentCompany::class)->set($this->empresa);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->fuerzaPosDeRetail($this->empresa, $this->limpiar);
        $this->turno = $this->abrirTurno(base: 200000);
    }

    protected function tearDown(): void
    {
        $fallos = [];

        foreach (array_reverse($this->limpiar) as $paso) {
            try {
                $paso();
            } catch (\Throwable $e) {
                $fallos[] = $e->getMessage();
            }
        }

        $this->limpiar = [];

        parent::tearDown();

        $this->assertSame([], $fallos, 'La limpieza falló: '.implode(' · ', $fallos));
    }

    /* ------------------------------------------------------------------ */
    /*  Lo que de verdad importa: el arqueo */
    /* ------------------------------------------------------------------ */

    /**
     * Una salida baja el esperado en caja.
     *
     * Es la razón de existir de toda la función. Sin esto, el pago del
     * domiciliario sale como faltante al cerrar.
     */
    public function test_una_salida_baja_el_esperado_en_caja(): void
    {
        $resumen = app(CashSessionSummary::class);

        $antes = (float) $resumen->compute($this->turno)['expected_cash'];
        $this->assertEqualsWithDelta(200000, $antes, 0.01, 'La base del turno.');

        $this->mover(CashMovement::OUT, 'gasto_menor', 'Domicilio ZZ', 8000);

        $despues = (float) $resumen->compute($this->turno->fresh())['expected_cash'];

        $this->assertEqualsWithDelta(192000, $despues, 0.01,
            'El esperado tiene que bajar: esa plata ya no está en el cajón.');
    }

    /** Y una entrada lo sube. */
    public function test_una_entrada_sube_el_esperado_en_caja(): void
    {
        $this->mover(CashMovement::IN, 'base', 'Cambio para la tarde ZZ', 50000);

        $esperado = (float) app(CashSessionSummary::class)
            ->compute($this->turno->fresh())['expected_cash'];

        $this->assertEqualsWithDelta(250000, $esperado, 0.01);
    }

    /** El resumen los desglosa, no los esconde dentro del neto. */
    public function test_el_resumen_desglosa_entradas_y_salidas(): void
    {
        $this->mover(CashMovement::IN, 'base', 'Cambio ZZ', 50000);
        $this->mover(CashMovement::OUT, 'gasto_menor', 'Domicilio ZZ', 8000);
        $this->mover(CashMovement::OUT, 'sangria', 'A la caja fuerte ZZ', 100000);

        $resumen = app(CashSessionSummary::class)->compute($this->turno->fresh());

        $this->assertSame(3, $resumen['movements']['count']);
        $this->assertEqualsWithDelta(50000, $resumen['movements']['in'], 0.01);
        $this->assertEqualsWithDelta(108000, $resumen['movements']['out'], 0.01);
        $this->assertEqualsWithDelta(-58000, $resumen['movements']['neto'], 0.01);

        // Por motivo, que es lo que deja contestar «cuánto sacamos en sangrías».
        $this->assertArrayHasKey('Sangría / traslado a caja fuerte', $resumen['movements']['por_motivo']);
        $this->assertEqualsWithDelta(
            -100000,
            $resumen['movements']['por_motivo']['Sangría / traslado a caja fuerte'],
            0.01,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  No se saca lo que no hay */
    /* ------------------------------------------------------------------ */

    /**
     * El cajón no puede quedar en negativo.
     *
     * Si el sistema lo permite, lo que aparece al cerrar no es un error de
     * digitación: es una caja que nunca tuvo esa plata, y a esa hora ya no se
     * reconstruye.
     */
    public function test_no_se_puede_sacar_mas_de_lo_que_hay(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/En la caja hay/i');

        app(CashMovementEngine::class)->registrar($this->turno, [
            'direction' => CashMovement::OUT,
            'reason' => 'sangria',
            'concept' => 'Más de lo que hay ZZ',
            'amount' => 500000,
        ]);
    }

    /** El mensaje dice cuánto hay y cuánto se intentó sacar. */
    public function test_el_error_dice_cuanto_hay(): void
    {
        try {
            app(CashMovementEngine::class)->registrar($this->turno, [
                'direction' => CashMovement::OUT,
                'reason' => 'sangria',
                'concept' => 'Mucho ZZ',
                'amount' => 500000,
            ]);

            $this->fail('Debió rechazarlo.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('200.000', $e->getMessage());
            $this->assertStringContainsString('500.000', $e->getMessage());
        }
    }

    /** Sacar exactamente lo que hay sí se puede: deja el cajón en cero. */
    public function test_se_puede_vaciar_el_cajon(): void
    {
        $this->mover(CashMovement::OUT, 'sangria', 'Todo a la caja fuerte ZZ', 200000);

        $esperado = (float) app(CashSessionSummary::class)
            ->compute($this->turno->fresh())['expected_cash'];

        $this->assertEqualsWithDelta(0, $esperado, 0.01);
    }

    /* ------------------------------------------------------------------ */
    /*  Contabilidad */
    /* ------------------------------------------------------------------ */

    /**
     * Siempre hay asiento, y va contra la cuenta del motivo.
     *
     * Si el efectivo baja y los libros no lo explican, la contabilidad
     * empieza a mentir en silencio.
     */
    public function test_el_movimiento_genera_asiento_cuadrado(): void
    {
        $movimiento = $this->mover(CashMovement::OUT, 'gasto_menor', 'Papelería ZZ', 30000);

        $this->assertNotNull($movimiento->journal_entry_id, 'Un movimiento sin asiento es un hueco contable.');

        $asiento = JournalEntry::withoutGlobalScopes()->find($movimiento->journal_entry_id);

        $this->assertSame(JournalEntry::STATUS_POSTED, $asiento->status);
        $this->assertEqualsWithDelta(30000, (float) $asiento->total_debit, 0.01);
        $this->assertEqualsWithDelta(30000, (float) $asiento->total_credit, 0.01);

        $lineas = DB::table('journal_entry_lines')->where('journal_entry_id', $asiento->id)->get();
        $this->assertCount(2, $lineas);
        $this->assertEqualsWithDelta(
            (float) $lineas->sum('debit'),
            (float) $lineas->sum('credit'),
            0.01,
            'Partida doble: lo que entra por un lado sale por el otro.',
        );
    }

    /**
     * Una sangría NO es un gasto.
     *
     * El dinero sigue siendo del negocio y solo cambió de sitio. Mandarla a
     * una cuenta de gasto dejaría el estado de resultados contando como gasto
     * plata que está guardada en la caja fuerte.
     */
    public function test_la_sangria_no_va_contra_una_cuenta_de_gasto(): void
    {
        $sangria = $this->mover(CashMovement::OUT, 'sangria', 'A la caja fuerte ZZ', 100000);
        $gasto = $this->mover(CashMovement::OUT, 'gasto_menor', 'Domicilio ZZ', 5000);

        $cuentaSangria = DB::table('accounts')->where('id', $sangria->counterpart_account_id)->value('code');
        $cuentaGasto = DB::table('accounts')->where('id', $gasto->counterpart_account_id)->value('code');

        $this->assertNotNull($cuentaSangria);
        $this->assertStringStartsWith('1', $cuentaSangria, 'Una sangría es un activo que cambia de sitio.');

        $this->assertNotNull($cuentaGasto);
        $this->assertStringStartsWith('5', $cuentaGasto, 'Un gasto menor sí es gasto.');
    }

    /** La entrada y la salida mueven la caja en sentidos contrarios. */
    public function test_la_entrada_debita_la_caja_y_la_salida_la_acredita(): void
    {
        $entrada = $this->mover(CashMovement::IN, 'base', 'Cambio ZZ', 40000);
        $salida = $this->mover(CashMovement::OUT, 'gasto_menor', 'Domicilio ZZ', 10000);

        $debitoCaja = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $entrada->journal_entry_id)
            ->where('account_id', $entrada->cash_account_id)
            ->value('debit');

        $creditoCaja = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $salida->journal_entry_id)
            ->where('account_id', $salida->cash_account_id)
            ->value('credit');

        $this->assertEqualsWithDelta(40000, (float) $debitoCaja, 0.01, 'Entra plata: la caja se debita.');
        $this->assertEqualsWithDelta(10000, (float) $creditoCaja, 0.01, 'Sale plata: la caja se acredita.');
    }

    /* ------------------------------------------------------------------ */
    /*  Anulación */
    /* ------------------------------------------------------------------ */

    /** Anular devuelve la plata y revierte el asiento. */
    public function test_anular_devuelve_la_plata_al_cajon(): void
    {
        $movimiento = $this->mover(CashMovement::OUT, 'gasto_menor', 'Me equivoqué ZZ', 25000);

        $conSalida = (float) app(CashSessionSummary::class)
            ->compute($this->turno->fresh())['expected_cash'];
        $this->assertEqualsWithDelta(175000, $conSalida, 0.01);

        app(CashMovementEngine::class)->anular($movimiento, 'Digité mal el valor ZZ');

        $despues = (float) app(CashSessionSummary::class)
            ->compute($this->turno->fresh())['expected_cash'];

        $this->assertEqualsWithDelta(200000, $despues, 0.01, 'La plata vuelve al cajón.');
        $this->assertTrue($movimiento->fresh()->estaAnulado());

        // Y queda el asiento contrario: no se borra el original.
        $reversos = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('reference', 'ANUL MC-'.str_pad((string) $movimiento->id, 6, '0', STR_PAD_LEFT))
            ->count();

        $this->assertSame(1, $reversos, 'La anulación reversa, no borra.');
    }

    /** Anular exige motivo escrito. */
    public function test_anular_exige_motivo(): void
    {
        $movimiento = $this->mover(CashMovement::OUT, 'gasto_menor', 'Algo ZZ', 5000);

        $this->expectException(\RuntimeException::class);

        app(CashMovementEngine::class)->anular($movimiento, '   ');
    }

    /** No se anula dos veces. */
    public function test_no_se_anula_dos_veces(): void
    {
        $movimiento = $this->mover(CashMovement::OUT, 'gasto_menor', 'Algo ZZ', 5000);

        app(CashMovementEngine::class)->anular($movimiento, 'Motivo ZZ');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya estaba anulado/i');

        app(CashMovementEngine::class)->anular($movimiento->fresh(), 'Otra vez ZZ');
    }

    /**
     * Un turno cerrado no se toca.
     *
     * El arqueo ya se firmó; cambiarlo por detrás lo convertiría en un
     * documento que no significa nada.
     */
    public function test_no_se_mueve_la_caja_de_un_turno_cerrado(): void
    {
        $this->turno->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya está cerrado/i');

        app(CashMovementEngine::class)->registrar($this->turno->fresh(), [
            'direction' => CashMovement::OUT,
            'reason' => 'gasto_menor',
            'concept' => 'Tarde ZZ',
            'amount' => 1000,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Validaciones */
    /* ------------------------------------------------------------------ */

    /** Sin concepto no se guarda: es lo único que explica la plata después. */
    public function test_el_concepto_es_obligatorio(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/para qué fue/i');

        app(CashMovementEngine::class)->registrar($this->turno, [
            'direction' => CashMovement::OUT,
            'reason' => 'gasto_menor',
            'concept' => '  ',
            'amount' => 5000,
        ]);
    }

    /** Un motivo de entrada no sirve para una salida. */
    public function test_el_motivo_tiene_que_corresponder_a_la_direccion(): void
    {
        $this->expectException(\RuntimeException::class);

        app(CashMovementEngine::class)->registrar($this->turno, [
            'direction' => CashMovement::OUT,
            'reason' => 'base',   // es de entrada
            'concept' => 'Cruzado ZZ',
            'amount' => 5000,
        ]);
    }

    /** Un movimiento en cero no es un movimiento. */
    public function test_el_valor_tiene_que_ser_mayor_que_cero(): void
    {
        $this->expectException(\RuntimeException::class);

        app(CashMovementEngine::class)->registrar($this->turno, [
            'direction' => CashMovement::IN,
            'reason' => 'base',
            'concept' => 'Nada ZZ',
            'amount' => 0,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Permisos y pantalla */
    /* ------------------------------------------------------------------ */

    /** El cajero lo trae por defecto: sin él la función no sirve. */
    public function test_el_cajero_puede_mover_la_caja(): void
    {
        $this->assertContains('pos.cash_movements', PermissionsCatalog::defaultForRole('cashier'));
        $this->assertContains('pos.cash_movements', PermissionsCatalog::defaultForRole('admin'));
        $this->assertArrayHasKey('pos.cash_movements', PermissionsCatalog::groups()['Ventas (futuro)']);
    }

    /** Y desde el POS se registra igual que desde el motor. */
    public function test_se_registra_desde_el_pos(): void
    {
        $this->dalePermisoDeMoverCaja();

        Livewire::test(PosTerminal::class)
            ->call('openCashMovementModal', 'out')
            ->assertSet('showCashMovementModal', true)
            ->set('cashMovementForm.reason', 'gasto_menor')
            ->set('cashMovementForm.concept', 'Domicilio desde el POS ZZ')
            ->set('cashMovementForm.amount', 12000)
            ->call('saveCashMovement');

        $movimiento = CashMovement::query()
            ->where('cash_register_session_id', $this->turno->id)
            ->where('concept', 'Domicilio desde el POS ZZ')
            ->first();

        $this->assertNotNull($movimiento, 'No se registró desde la pantalla.');
        $this->assertEqualsWithDelta(12000, (float) $movimiento->amount, 0.01);
        $this->assertNotNull($movimiento->journal_entry_id);

        $this->limpiar[] = fn () => $this->borraMovimiento($movimiento);
    }

    // --------------------------------------------------------- auxiliares

    /**
     * Le da al usuario el permiso de mover la caja.
     *
     * El permiso existe en `PermissionsCatalog`, pero la fila en la base la
     * crea el deploy (`RolesSeeder` + `roles:sync-catalog`). La base de
     * desarrollo puede estar en cualquier punto de ese camino, así que la
     * prueba no se apoya en ella: crea lo que necesita y lo devuelve.
     */
    private function dalePermisoDeMoverCaja(): void
    {
        $permiso = Permission::firstOrCreate([
            'name' => 'pos.cash_movements',
            'guard_name' => 'web',
        ]);

        $loCreamos = $permiso->wasRecentlyCreated;
        $yaLoTenia = $this->cajero->hasDirectPermission($permiso);

        if (! $yaLoTenia) {
            $this->cajero->givePermissionTo($permiso);
        }

        $this->limpiar[] = function () use ($permiso, $loCreamos, $yaLoTenia) {
            if (! $yaLoTenia) {
                $this->cajero->revokePermissionTo($permiso);
            }

            if ($loCreamos) {
                $permiso->delete();
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        };

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->cajero->unsetRelation('permissions')->unsetRelation('roles');
    }

    private function mover(string $direccion, string $motivo, string $concepto, float $monto): CashMovement
    {
        $movimiento = app(CashMovementEngine::class)->registrar($this->turno->fresh(), [
            'direction' => $direccion,
            'reason' => $motivo,
            'concept' => $concepto,
            'amount' => $monto,
        ]);

        $this->limpiar[] = fn () => $this->borraMovimiento($movimiento);

        return $movimiento;
    }

    private function borraMovimiento(CashMovement $movimiento): void
    {
        // Los asientos del movimiento y los de su anulación.
        $referencias = [
            'MC-'.str_pad((string) $movimiento->id, 6, '0', STR_PAD_LEFT),
            'ANUL MC-'.str_pad((string) $movimiento->id, 6, '0', STR_PAD_LEFT),
        ];

        $asientos = DB::table('journal_entries')
            ->where('company_id', $this->empresa->id)
            ->whereIn('reference', $referencias)
            ->pluck('id');

        DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
        DB::table('cash_movements')->where('id', $movimiento->id)->delete();
        DB::table('journal_entries')->whereIn('id', $asientos)->delete();
    }

    private function abrirTurno(float $base): CashRegisterSession
    {
        // Los turnos abiertos que haya quedado de otra prueba estorban: el POS
        // toma el más reciente y el arqueo contaría lo que no es.
        $previos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('cashier_user_id', $this->cajero->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->pluck('id');

        if ($previos->isNotEmpty()) {
            DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

            $this->limpiar[] = fn () => DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_OPEN, 'closed_at' => null]);
        }

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->cajero->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => $base,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }
}
