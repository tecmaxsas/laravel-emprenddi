<?php

namespace Tests\Feature;

use App\Models\Billiards\BilliardCharge;
use App\Models\Billiards\BilliardSession;
use App\Models\Billiards\BilliardTable;
use App\Models\SaleInvoice;
use App\Services\Billiards\BilliardAccount;
use App\Services\Billiards\BilliardBillingEngine;
use App\Services\Billiards\BilliardProductProvisioner;
use App\Services\Billiards\BilliardSessionEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MontaUnSalonDeBillar;
use Tests\TestCase;

/**
 * La cuenta de una mesa de billar, de principio a fin.
 *
 * Lo que un salón hace de verdad en una noche: abren la mesa, piden
 * cervezas, paran el reloj para ir a comer, se cambian a la mesa de al lado
 * porque el paño estaba malo, y al final pagan una sola cuenta.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class BilliardSessionTest extends TestCase
{
    use MontaUnSalonDeBillar;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->montaUnSalonDeBillar($this->limpiar);

        $this->actingAs($this->cajero);
        app(CurrentCompany::class)->set($this->empresa);

        $this->abreUnTurnoDeCaja($this->limpiar);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        $fallos = [];

        foreach (array_reverse($this->limpiar) as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $fallos[] = $e->getMessage();
            }
        }

        $this->limpiar = [];

        parent::tearDown();

        $this->assertSame([], $fallos, 'La limpieza falló: '.implode(' · ', $fallos));
    }

    // ------------------------------------------------------------- abrir

    /** Abrir la mesa la marca ocupada y arranca el reloj. */
    public function test_abrir_la_mesa_la_ocupa(): void
    {
        $sesion = $this->abrirMesa();

        $this->assertSame(BilliardSession::STATUS_OPEN, $sesion->status);
        $this->assertSame(BilliardTable::STATUS_OCCUPIED, $this->mesa->fresh()->status);
        $this->assertNotNull($sesion->rate_id, 'La tarifa se resuelve al abrir y queda guardada.');
        $this->assertEquals($sesion->started_at, $sesion->time_anchor_at);
    }

    /** Dos meseros no pueden abrir la misma mesa. */
    public function test_no_se_abre_una_mesa_ya_ocupada(): void
    {
        $this->abrirMesa();

        $this->expectExceptionMessageMatches('/no está libre/i');
        $this->abrirMesa();
    }

    /** Una mesa en mantenimiento no se abre. */
    public function test_no_se_abre_una_mesa_en_mantenimiento(): void
    {
        $this->mesa->update(['status' => BilliardTable::STATUS_MAINTENANCE]);

        $this->expectExceptionMessageMatches('/mantenimiento/i');
        $this->abrirMesa();
    }

    // ------------------------------------------------------------ pausas

    /**
     * El tiempo pausado no se cobra.
     *
     * Es el reclamo más común del salón: «yo paré la mesa para ir a comer».
     * Jugaron de 2 a 3 y de 4 a 4:30 — hora y media, no dos y media.
     */
    public function test_el_tiempo_pausado_no_se_cobra(): void
    {
        Carbon::setTestNow('2026-11-17 14:00:00');
        $sesion = $this->abrirMesa();

        Carbon::setTestNow('2026-11-17 15:00:00');
        app(BilliardSessionEngine::class)->pausar($sesion->fresh(['pauses']), 'Se fueron a comer');

        Carbon::setTestNow('2026-11-17 16:00:00');
        app(BilliardSessionEngine::class)->reanudar($sesion->fresh(['pauses']));

        Carbon::setTestNow('2026-11-17 16:30:00');
        $cerrada = app(BilliardSessionEngine::class)->cerrar($sesion->fresh(['pauses']));

        $this->assertSame(90, $cerrada->charge_minutes, 'Hora y media jugada, no dos y media.');
        $this->assertEqualsWithDelta(15000, (float) $cerrada->time_total, 0.01);
    }

    /** Mientras está en pausa, el reloj se queda quieto. */
    public function test_en_pausa_el_reloj_no_avanza(): void
    {
        Carbon::setTestNow('2026-11-17 14:00:00');
        $sesion = $this->abrirMesa();

        Carbon::setTestNow('2026-11-17 15:00:00');
        app(BilliardSessionEngine::class)->pausar($sesion->fresh(['pauses']));

        Carbon::setTestNow('2026-11-17 18:00:00');
        $this->assertSame(60, $sesion->fresh(['pauses'])->minutosJugados(),
            'Tres horas en pausa no suman un minuto cobrable.');
    }

    /** La pausa queda registrada con hora y motivo: es lo que zanja la discusión. */
    public function test_la_pausa_queda_registrada(): void
    {
        $sesion = $this->abrirMesa();

        app(BilliardSessionEngine::class)->pausar($sesion->fresh(['pauses']), 'Se fueron a comer');

        $pausa = $sesion->pauses()->first();

        $this->assertNotNull($pausa);
        $this->assertSame('Se fueron a comer', $pausa->reason);
        $this->assertSame($this->cajero->id, $pausa->paused_by_user_id);
    }

    // ----------------------------------------------------------- consumos

    /** Las cervezas se cargan a la cuenta mientras juegan. */
    public function test_los_consumos_se_cargan_a_la_cuenta(): void
    {
        $sesion = $this->abrirMesa();

        app(BilliardAccount::class)->agregarConsumo($sesion, [
            'product_id' => $this->cerveza->id,
            'quantity' => 3,
        ]);

        $sesion->refresh();

        $this->assertEqualsWithDelta(15000, (float) $sesion->consumption_total, 0.01);
        $this->assertEqualsWithDelta(15000, (float) $sesion->total, 0.01);
    }

    /** Un renglón de consumo se puede quitar mientras la cuenta esté abierta. */
    public function test_un_consumo_se_puede_quitar(): void
    {
        $sesion = $this->abrirMesa();

        $cargo = app(BilliardAccount::class)->agregarConsumo($sesion, [
            'product_id' => $this->cerveza->id,
            'quantity' => 1,
        ]);

        app(BilliardAccount::class)->quitar($cargo);

        $this->assertEqualsWithDelta(0, (float) $sesion->fresh()->total, 0.01);
    }

    /**
     * El tiempo NO se quita a mano.
     *
     * Lo decide el reloj, no el cajero. Para perdonarlo está el ajuste, que
     * deja constancia de quién regaló esa plata.
     */
    public function test_el_tiempo_no_se_quita_a_mano(): void
    {
        Carbon::setTestNow('2026-11-17 14:00:00');
        $sesion = $this->abrirMesa();

        Carbon::setTestNow('2026-11-17 15:00:00');
        $cerrada = app(BilliardSessionEngine::class)->cerrar($sesion);

        $tiempo = $cerrada->charges()->where('kind', BilliardCharge::KIND_TIME)->first();
        $this->assertNotNull($tiempo);

        // Se reabre a mano solo para probar la regla del renglón, no el
        // ciclo de vida: cerrada, `quitar` fallaría por otro motivo.
        $cerrada->update(['status' => BilliardSession::STATUS_OPEN]);

        $this->expectExceptionMessageMatches('/no se quita a mano/i');
        app(BilliardAccount::class)->quitar($tiempo->fresh());
    }

    // --------------------------------------------------------- traslados

    /**
     * Al trasladar, el tiempo jugado se congela a la tarifa de la mesa vieja.
     *
     * Si no, quien se cambia porque la primera mesa tenía el paño roto
     * termina pagando toda la noche a la tarifa de la mesa cara.
     */
    public function test_el_traslado_congela_el_tiempo_de_la_mesa_anterior(): void
    {
        Carbon::setTestNow('2026-11-17 14:00:00');
        $sesion = $this->abrirMesa();

        Carbon::setTestNow('2026-11-17 15:00:00');
        $movida = app(BilliardSessionEngine::class)->trasladar($sesion->fresh(['pauses']), $this->otraMesa);

        $this->assertSame($this->otraMesa->id, $movida->table_id);
        $this->assertSame(BilliardTable::STATUS_FREE, $this->mesa->fresh()->status);
        $this->assertSame(BilliardTable::STATUS_OCCUPIED, $this->otraMesa->fresh()->status);

        $tiempo = $movida->charges()->where('kind', BilliardCharge::KIND_TIME)->get();
        $this->assertCount(1, $tiempo, 'El tramo de la mesa anterior queda cobrado en su propio renglón.');
        $this->assertEqualsWithDelta(10000, (float) $tiempo->first()->total, 0.01);

        $this->assertEquals(
            Carbon::parse('2026-11-17 15:00:00'),
            $movida->time_anchor_at,
            'El reloj del tramo nuevo arranca al trasladar.',
        );
    }

    /** Una cuenta cerrada tiene un renglón de tiempo por cada mesa en la que jugaron. */
    public function test_dos_mesas_dejan_dos_renglones_de_tiempo(): void
    {
        Carbon::setTestNow('2026-11-17 14:00:00');
        $sesion = $this->abrirMesa();

        Carbon::setTestNow('2026-11-17 15:00:00');
        app(BilliardSessionEngine::class)->trasladar($sesion->fresh(['pauses']), $this->otraMesa);

        Carbon::setTestNow('2026-11-17 16:00:00');
        $cerrada = app(BilliardSessionEngine::class)->cerrar($sesion->fresh(['pauses']));

        $this->assertCount(2, $cerrada->charges()->where('kind', BilliardCharge::KIND_TIME)->get());
        $this->assertEqualsWithDelta(20000, (float) $cerrada->time_total, 0.01);
    }

    // ------------------------------------------------------------ cerrar

    /** Cerrar libera la mesa. */
    public function test_cerrar_libera_la_mesa(): void
    {
        Carbon::setTestNow('2026-11-17 14:00:00');
        $sesion = $this->abrirMesa();

        Carbon::setTestNow('2026-11-17 15:00:00');
        app(BilliardSessionEngine::class)->cerrar($sesion);

        $this->assertSame(BilliardTable::STATUS_FREE, $this->mesa->fresh()->status);
        $this->assertSame(BilliardSession::STATUS_CLOSED, $sesion->fresh()->status);
    }

    /**
     * El valor digitado a mano reemplaza al de la tarifa, pero deja rastro.
     *
     * Si la diferencia no queda en el desglose, la cortesía se pierde: nadie
     * puede saber después cuánto se dejó de cobrar.
     */
    public function test_el_valor_a_mano_deja_rastro_de_lo_que_la_tarifa_cobraba(): void
    {
        Carbon::setTestNow('2026-11-17 14:00:00');
        $sesion = $this->abrirMesa();

        Carbon::setTestNow('2026-11-17 16:00:00');
        $cerrada = app(BilliardSessionEngine::class)->cerrar($sesion, null, 12000);

        $this->assertEqualsWithDelta(12000, (float) $cerrada->time_total, 0.01);

        $etiquetas = collect($cerrada->breakdown)->pluck('label')->implode(' | ');
        $this->assertStringContainsString('20.000', $etiquetas,
            'En el desglose tiene que quedar lo que la tarifa habría cobrado.');
    }

    /** Anular una cuenta con consumos haría desaparecer productos de la nevera. */
    public function test_no_se_anula_una_cuenta_con_consumos(): void
    {
        $sesion = $this->abrirMesa();

        app(BilliardAccount::class)->agregarConsumo($sesion, [
            'product_id' => $this->cerveza->id,
            'quantity' => 1,
        ]);

        $this->expectExceptionMessageMatches('/consumos/i');
        app(BilliardSessionEngine::class)->anular($sesion->fresh(), 'Me equivoqué de mesa');
    }

    /** Anular una mesa recién abierta la libera. */
    public function test_anular_libera_la_mesa(): void
    {
        $sesion = $this->abrirMesa();

        app(BilliardSessionEngine::class)->anular($sesion, 'Me equivoqué de mesa');

        $this->assertSame(BilliardSession::STATUS_CANCELLED, $sesion->fresh()->status);
        $this->assertSame(BilliardTable::STATUS_FREE, $this->mesa->fresh()->status);
    }

    // --------------------------------------------------------- facturar

    /** La factura lleva el tiempo y los consumos, cada uno en su línea. */
    public function test_la_factura_lleva_tiempo_y_consumos(): void
    {
        Carbon::setTestNow('2026-11-17 14:00:00');
        $sesion = $this->abrirMesa();

        app(BilliardAccount::class)->agregarConsumo($sesion, [
            'product_id' => $this->cerveza->id,
            'quantity' => 2,
        ]);

        Carbon::setTestNow('2026-11-17 16:00:00');
        $cerrada = app(BilliardSessionEngine::class)->cerrar($sesion->fresh(['pauses']));

        $factura = app(BilliardBillingEngine::class)->issueForSession($cerrada, [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => $this->cuentaCaja,
        ]);

        $this->assertSame(SaleInvoice::ORIGIN_BILLIARDS, $factura->origin);
        $this->assertCount(2, $factura->lines, 'Una línea de tiempo y una de cerveza.');
        $this->assertEqualsWithDelta(30000, (float) $factura->total, 0.01,
            'Dos horas a $10.000 más dos cervezas a $5.000.');
        $this->assertEqualsWithDelta(30000, (float) $factura->paid_amount, 0.01);
        $this->assertSame($factura->id, $cerrada->fresh()->sale_invoice_id);
    }

    /** La cortesía baja el total y viaja como descuento global, no como línea negativa. */
    public function test_la_cortesia_entra_como_descuento_global(): void
    {
        Carbon::setTestNow('2026-11-17 14:00:00');
        $sesion = $this->abrirMesa();

        app(BilliardAccount::class)->agregarAjuste($sesion, 'Cortesía de la casa', 5000);

        Carbon::setTestNow('2026-11-17 16:00:00');
        $cerrada = app(BilliardSessionEngine::class)->cerrar($sesion->fresh(['pauses']));

        $this->assertEqualsWithDelta(15000, (float) $cerrada->total, 0.01,
            '$20.000 de tiempo menos $5.000 de cortesía.');

        $factura = app(BilliardBillingEngine::class)->issueForSession($cerrada, [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => $this->cuentaCaja,
        ]);

        foreach ($factura->lines as $linea) {
            $this->assertGreaterThan(0, (float) $linea->total,
                'La DIAN rechaza una línea de factura en negativo: la rebaja va en el descuento global.');
        }

        $this->assertEqualsWithDelta(15000, (float) $factura->total, 0.01);
    }

    /** Una cuenta que no se cerró no se factura. */
    public function test_no_se_factura_una_cuenta_abierta(): void
    {
        $sesion = $this->abrirMesa();

        $this->expectExceptionMessageMatches('/cuenta cerrada/i');
        app(BilliardBillingEngine::class)->issueForSession($sesion, [
            'payment_method' => 'cash',
            'account_id' => $this->cuentaCaja,
        ]);
    }

    /**
     * Si alguien borra «Alquiler de mesa de billar», se restaura.
     *
     * Es la caída que ya pasó en el parqueadero: la factura guardaba el id
     * del producto, al releerla con los scopes normales salía null y el
     * cobro reventaba con «Call to a member function isKit() on null» con el
     * cliente en el mostrador.
     */
    public function test_el_producto_de_la_mesa_se_restaura_si_lo_borran(): void
    {
        $producto = app(BilliardProductProvisioner::class)->ensure($this->empresa);
        $producto->delete();

        $recuperado = app(BilliardProductProvisioner::class)->ensure($this->empresa);

        $this->assertFalse($recuperado->trashed());
        $this->assertSame($producto->id, $recuperado->id);
        $this->assertTrue((bool) $recuperado->is_sellable);
    }

    // --------------------------------------------------------- auxiliares

    private function abrirMesa(): BilliardSession
    {
        return app(BilliardSessionEngine::class)->abrir([
            'table_id' => $this->mesa->id,
            'players' => 2,
            'customer_label' => 'Don Jorge',
        ]);
    }
}
