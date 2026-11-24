<?php

namespace Tests\Feature;

use App\Filament\App\Pages\OrderTaking\MonthlyPendingSummary;
use App\Filament\App\Pages\OrderTaking\PendingDeliveriesReport;
use App\Models\Company;
use App\Models\OrderTaking\Delivery;
use App\Models\OrderTaking\Order;
use App\Models\SaleInvoice;
use App\Services\OrderTaking\OrderEngine;
use App\Services\OrderTaking\PendingDeliveries;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MontaPedidos;
use Tests\TestCase;

/**
 * Lo que no se entregó: cerrar el pedido con faltantes, y verlo después.
 *
 * Tres cosas que el negocio pidió y que se sostienen entre sí:
 *
 *   - la factura del despacho vence según los días de crédito del cliente;
 *   - un pedido con líneas sin despachar se puede dar por terminado;
 *   - y al cerrarlo, lo pendiente NO desaparece de los informes.
 *
 * La tercera es la que importa vigilar. Si cerrar borrara el faltante,
 * cerrar sería una forma de esconder el problema y nadie podría contestar
 * cuánto se dejó de vender en el mes.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class OrderTakingPendientesTest extends TestCase
{
    use MontaPedidos;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tomaElUsuarioDePruebas();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }

        $this->limpiar = [];

        parent::tearDown();
    }

    // ------------------------------------------- 1. fecha de vencimiento

    /** La factura del despacho vence a los días de crédito del cliente. */
    public function test_la_factura_vence_segun_los_dias_de_credito_del_cliente(): void
    {
        $cliente = $this->cliente();
        $cliente->update(['credit_days' => 30]);

        $factura = $this->facturaDeUnDespacho($cliente);

        $this->assertSame(30, (int) $factura->payment_terms_days);
        $this->assertSame(
            now()->copy()->addDays(30)->toDateString(),
            $factura->due_date?->toDateString(),
            'El vencimiento se cuenta desde hoy, que es cuando se emite la factura.',
        );
    }

    /** Sin días de crédito, la factura es de contado y no inventa vencimiento. */
    public function test_sin_dias_de_credito_no_hay_vencimiento(): void
    {
        $cliente = $this->cliente();
        $cliente->update(['credit_days' => 0]);

        $factura = $this->facturaDeUnDespacho($cliente);

        $this->assertSame(0, (int) $factura->payment_terms_days);
        $this->assertNull($factura->due_date);
    }

    /**
     * Y sale de `credit_days`, no de `payment_terms_days`.
     *
     * En la ficha del tercero son dos campos distintos: el primero es «Días
     * de crédito» y el segundo «Días de pago — aplicable a proveedores».
     * Tomar el de proveedores le pondría a la factura del cliente un plazo
     * que no pactó nadie, y la cartera se cobraría en la fecha equivocada.
     */
    public function test_usa_los_dias_de_credito_y_no_los_de_proveedor(): void
    {
        $cliente = $this->cliente();
        $cliente->update(['credit_days' => 15, 'payment_terms_days' => 90]);

        $factura = $this->facturaDeUnDespacho($cliente);

        $this->assertSame(15, (int) $factura->payment_terms_days,
            'El plazo es el de crédito del cliente (15), no el de proveedores (90).');
    }

    // --------------------------------------------- 2. cerrar con pendientes

    /** Cerrar deja el pedido terminado sin tocar lo que falta. */
    public function test_cerrar_no_toca_las_cantidades_pendientes(): void
    {
        $order = $this->pedidoConDespachoParcial();
        $pendientesAntes = $order->fresh()->unidadesPendientes();

        $cerrado = app(OrderEngine::class)->cerrarConPendientes($order, 'Sin existencias, el cliente ya no lo quiere');

        $this->assertSame(Order::STATUS_CLOSED, $cerrado->status);
        $this->assertNotNull($cerrado->closed_at);
        $this->assertSame($this->user->id, $cerrado->closed_by_user_id);
        $this->assertSame('Sin existencias, el cliente ya no lo quiere', $cerrado->close_reason);

        $this->assertSame($pendientesAntes, $cerrado->unidadesPendientes(),
            'Cerrar no es despachar: lo que falta sigue faltando.');
    }

    /** Sin motivo no se cierra. */
    public function test_cerrar_exige_motivo(): void
    {
        $order = $this->pedidoConDespachoParcial();

        $this->expectExceptionMessageMatches('/motivo/i');
        app(OrderEngine::class)->cerrarConPendientes($order, '   ');
    }

    /** Un pedido cerrado ya no admite despachos. */
    public function test_un_pedido_cerrado_no_admite_despachos(): void
    {
        $order = $this->pedidoConDespachoParcial();
        $engine = app(OrderEngine::class);
        $engine->cerrarConPendientes($order, 'Se cierra');

        $this->expectExceptionMessageMatches('/cerró con pendientes/i');
        $engine->registerDelivery(
            $order->fresh(),
            [['order_item_id' => $order->items()->first()->id, 'quantity' => 1]],
        );
    }

    /**
     * Y recalcular el estado no lo reabre solo.
     *
     * `refreshDeliveryStatus` corre detrás de cada despacho y de cada
     * corrección. Sin la guarda volvería a poner «parcial» un pedido que
     * alguien dio por terminado, y nadie se enteraría.
     */
    public function test_recalcular_el_estado_no_reabre_un_pedido_cerrado(): void
    {
        $order = $this->pedidoConDespachoParcial();
        $engine = app(OrderEngine::class);
        $engine->cerrarConPendientes($order, 'Se cierra');

        $engine->refreshDeliveryStatus($order->fresh());

        $this->assertSame(Order::STATUS_CLOSED, $order->fresh()->status);
    }

    /** Reabrir lo devuelve a su estado real. */
    public function test_reabrir_devuelve_el_pedido_a_su_estado(): void
    {
        $order = $this->pedidoConDespachoParcial();
        $engine = app(OrderEngine::class);
        $engine->cerrarConPendientes($order, 'Me equivoqué');

        $reabierto = $engine->reabrir($order->fresh());

        $this->assertSame(Order::STATUS_PARTIAL_DELIVERED, $reabierto->status);
        $this->assertNull($reabierto->closed_at);
        $this->assertSame('Me equivoqué', $reabierto->close_reason,
            'El motivo se conserva: es historia de lo que pasó.');
    }

    // ------------------------------------------------- 3 y 4. los informes

    /**
     * Lo pendiente de un pedido CERRADO sigue contando.
     *
     * Es el punto de poder cerrarlos. Si desapareciera del informe, cerrar
     * sería una manera de esconder el faltante.
     */
    public function test_lo_pendiente_de_un_pedido_cerrado_sigue_en_el_informe(): void
    {
        $order = $this->pedidoConDespachoParcial();
        app(OrderEngine::class)->cerrarConPendientes($order, 'Sin existencias');

        $pendientes = app(PendingDeliveries::class);
        $filtros = ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()];

        $delPedido = $pendientes->filas($filtros)->firstWhere('pedido', $order->fullNumber());

        $this->assertNotNull($delPedido, 'El faltante de un pedido cerrado no puede desaparecer.');
        $this->assertEqualsWithDelta(6.0, $delPedido['pendiente'], 0.001, 'Se pidieron 10 y salieron 4.');
        $this->assertTrue($delPedido['cerrado']);
        $this->assertSame('Sin existencias', $delPedido['motivo_cierre']);
    }

    /** Y se puede aislar: solo lo que todavía se puede despachar. */
    public function test_el_filtro_separa_lo_que_todavia_se_puede_despachar(): void
    {
        $order = $this->pedidoConDespachoParcial();
        app(OrderEngine::class)->cerrarConPendientes($order, 'Sin existencias');

        $filtros = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'incluir_cerrados' => false,
        ];

        $this->assertNull(
            app(PendingDeliveries::class)->filas($filtros)->firstWhere('pedido', $order->fullNumber()),
            'Con el filtro puesto, el pedido cerrado no sale.',
        );
    }

    /** Un pedido ANULADO no es un faltante: esa venta no existe. */
    public function test_un_pedido_anulado_no_cuenta_como_pendiente(): void
    {
        $order = $this->pedidoConDespachoParcial();
        $order->update(['status' => Order::STATUS_CANCELLED]);

        $filtros = ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()];

        $this->assertNull(
            app(PendingDeliveries::class)->filas($filtros)->firstWhere('pedido', $order->fullNumber()),
            'Un pedido anulado nunca se va a despachar: sumarlo infla el faltante.',
        );
    }

    /** El consolidado agrupa por cliente y por referencia. */
    public function test_el_consolidado_mensual_agrupa_por_cliente_y_referencia(): void
    {
        $order = $this->pedidoConDespachoParcial();
        $cliente = $order->customer;

        $consolidado = app(PendingDeliveries::class)->consolidadoMensual(now()->format('Y-m'));

        $delCliente = $consolidado->firstWhere('cliente', $cliente->name);

        $this->assertNotNull($delCliente, 'El cliente con faltantes tiene que salir en el consolidado.');
        $this->assertNotEmpty($delCliente['lineas']);
        $this->assertGreaterThan(0, $delCliente['valor']);
        $this->assertContains($order->fullNumber(), $delCliente['lineas'][0]['pedidos'],
            'Cada referencia dice de qué pedidos viene.');
    }

    /** Las exportaciones responden y salen del mismo servicio. */
    public function test_las_exportaciones_responden(): void
    {
        $this->pedidoConDespachoParcial();

        $rango = ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString()];

        $this->get(route('order-taking.reports.export.pending', $rango))->assertSuccessful();
        $this->get(route('order-taking.reports.pdf.pending', $rango))->assertSuccessful();
        $this->get(route('order-taking.reports.export.monthly-pending', ['mes' => now()->format('Y-m')]))->assertSuccessful();
        $this->get(route('order-taking.reports.pdf.monthly-pending', ['mes' => now()->format('Y-m')]))->assertSuccessful();
    }

    // --------------------------------------------- 5. notas del despacho

    /**
     * Las notas del despacho se editan después, incluso ya facturado.
     *
     * Es lo que pidió el cliente y el motivo es concreto: en las notas
     * anotan la guía de transporte, y la guía casi nunca llega el mismo día
     * que se despacha.
     */
    public function test_las_notas_del_despacho_se_editan_despues_de_facturar(): void
    {
        $cliente = $this->cliente();
        $sede = $this->sedeConResolucion();
        $engine = app(OrderEngine::class);

        $order = $engine->recomputeTotals($this->pedidoDe($cliente));
        $order->update(['location_id' => $sede->id]);

        $delivery = $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 4]],
            'ZZ-REM-G',
            'Sin guía todavía',
        );

        $factura = $engine->invoiceDelivery($delivery->fresh(), 'pos', allowNegativeStock: true);
        $this->olvidarFactura($factura);

        // La guía llega dos días después.
        $delivery->fresh()->update(['notes' => 'Guía 1234567 — Servientrega']);

        $this->assertSame('Guía 1234567 — Servientrega', $delivery->fresh()->notes);
        $this->assertTrue($delivery->fresh()->isInvoiced(),
            'Y sigue facturado: editar la nota no toca la factura.');
    }

    // ------------------------------------------------------ las pantallas

    /**
     * Las dos pantallas nuevas abren.
     *
     * Un esquema de formulario de Filament mal escrito no falla al
     * desplegar: falla cuando el usuario abre la página. Montarlas aquí es
     * lo único que lo atrapa antes.
     */
    public function test_las_pantallas_de_pendientes_abren(): void
    {
        $order = $this->pedidoConDespachoParcial();

        $empresa = Company::find($this->companyId);
        $modulos = $empresa->active_modules ?? [];
        $empresa->update(['active_modules' => array_values(array_unique([...$modulos, 'order_taking']))]);
        app(CurrentCompany::class)->set($empresa->refresh());
        Filament::setCurrentPanel(Filament::getPanel('app'));

        try {
            Livewire::test(PendingDeliveriesReport::class)
                ->assertOk()
                ->assertSee($order->customer->name);

            Livewire::test(MonthlyPendingSummary::class)
                ->assertOk()
                ->assertSee($order->customer->name);
        } finally {
            $empresa->update(['active_modules' => $modulos]);
        }
    }

    // --------------------------------------------------------- auxiliares

    /** Pedido de 10 unidades con 4 despachadas: quedan 6 pendientes. */
    private function pedidoConDespachoParcial(): Order
    {
        $engine = app(OrderEngine::class);
        $order = $engine->recomputeTotals($this->pedidoDe($this->cliente()));

        $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 4]],
        );

        return $order->fresh(['items', 'customer']);
    }

    private function facturaDeUnDespacho($cliente): SaleInvoice
    {
        $sede = $this->sedeConResolucion();
        $engine = app(OrderEngine::class);

        $order = $engine->recomputeTotals($this->pedidoDe($cliente));
        $order->update(['location_id' => $sede->id]);

        $delivery = $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 10]],
        );

        $factura = $engine->invoiceDelivery($delivery->fresh(), 'pos', allowNegativeStock: true);
        $this->olvidarFactura($factura);

        return $factura;
    }

    private function olvidarFactura(SaleInvoice $factura): void
    {
        // Los cobros antes que la factura: borrarla sola deja el pago
        // apuntando a una factura que ya no existe.
        $this->limpiar[] = function () use ($factura) {
            DB::table('payments')
                ->where('paymentable_type', SaleInvoice::class)
                ->where('paymentable_id', $factura->id)
                ->delete();

            SaleInvoice::withoutGlobalScopes()->whereKey($factura->id)->forceDelete();
        };
    }
}
