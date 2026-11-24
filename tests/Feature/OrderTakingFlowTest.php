<?php

namespace Tests\Feature;

use App\Filament\App\Pages\OrderTaking\DeliveryReport;
use App\Filament\App\Pages\OrderTaking\NewOrder;
use App\Filament\App\Resources\OrderTaking\OrderResource;
use App\Filament\App\Resources\OrderTaking\OrderResource\Pages\ListOrders;
use App\Filament\App\Resources\OrderTaking\OrderResource\Pages\ViewOrder;
use App\Filament\App\Resources\OrderTaking\OrderResource\RelationManagers\DeliveriesRelationManager;
use App\Filament\App\Resources\OrderTaking\OrderResource\RelationManagers\PaymentsRelationManager;
use App\Models\Account;
use App\Models\Company;
use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\Location;
use App\Models\OrderTaking\Delivery;
use App\Models\OrderTaking\DeliveryItem;
use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\OrderItem;
use App\Models\OrderTaking\OrderRetention;
use App\Models\OrderTaking\Payment;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\OrderTaking\OrderEngine;
use App\Support\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\MontaPedidos;
use Tests\TestCase;

/**
 * Los tres cambios de fondo del módulo Toma pedidos:
 *
 *   - la retención configurada en el tercero se aplica al pedido
 *   - el saldo se mide contra el neto a pagar, no contra el total
 *   - el abono cuelga del despacho, no del pedido
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class OrderTakingFlowTest extends TestCase
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





    public function test_la_retencion_del_cliente_se_sugiere_sobre_la_base_gravable(): void
    {
        $cliente = $this->cliente();
        $tax = $this->retencion(2.5);
        $cliente->retentionTaxes()->sync([$tax->id]);

        $sugeridas = app(OrderEngine::class)->suggestRetentionsFor($cliente, 1000000);

        $this->assertCount(1, $sugeridas);
        $this->assertSame('ZZRF', $sugeridas[0]['tax_code']);
        $this->assertSame(25000.0, $sugeridas[0]['amount'], 'El 2.5% de 1.000.000 son 25.000.');
    }

    public function test_el_saldo_se_mide_contra_el_neto_a_pagar_no_contra_el_total(): void
    {
        $cliente = $this->cliente();
        $tax = $this->retencion(2.5);
        $cliente->retentionTaxes()->sync([$tax->id]);

        $engine = app(OrderEngine::class);
        $order = $this->pedidoDe($cliente);

        $engine->syncRetentions($order, $engine->suggestRetentionsFor($cliente, 1000000));
        $order = $engine->recomputeTotals($order->fresh(['items', 'retentions']));

        $this->assertSame('1190000.00', $order->total);
        $this->assertSame('190000.00', $order->tax_total, 'El IVA de la linea, no el unitario.');
        $this->assertSame(
            round((float) $order->subtotal + (float) $order->tax_total, 2),
            round((float) $order->total, 2),
            'El total tiene que ser subtotal + IVA.'
        );
        $this->assertSame('25000.00', $order->retention_total);
        $this->assertSame('1165000.00', $order->net_payable, 'Total menos la retención.');
        $this->assertSame('1165000.00', $order->balance, 'La retención no es saldo por cobrar.');
    }

    public function test_el_abono_queda_ligado_al_despacho(): void
    {
        $cliente = $this->cliente();
        $engine = app(OrderEngine::class);
        $order = $engine->recomputeTotals($this->pedidoDe($cliente));

        $delivery = $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 4]],
            'ZZ-REM-1',
        );

        $this->assertInstanceOf(Delivery::class, $delivery);
        $this->assertSame(476000.0, $delivery->value(), '4 unidades × 119.000.');

        $payment = $engine->registerPayment($delivery, ['amount' => 200000, 'payment_method' => 'cash']);

        $this->assertSame($delivery->id, $payment->delivery_id, 'El abono debe colgar del despacho.');
        $this->assertSame(200000.0, $delivery->fresh()->paidAmount());

        $order->refresh();
        $this->assertSame('200000.00', $order->paid_amount);
        $this->assertSame('990000.00', $order->balance);
        $this->assertSame('parcial', $order->payment_status);
    }

    public function test_el_abono_no_puede_exceder_el_saldo_del_pedido(): void
    {
        $cliente = $this->cliente();
        $engine = app(OrderEngine::class);
        $order = $engine->recomputeTotals($this->pedidoDe($cliente));

        $delivery = $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 1]],
        );

        $this->expectExceptionMessageMatches('/excede el saldo/i');
        $engine->registerPayment($delivery, ['amount' => 99999999, 'payment_method' => 'cash']);
    }

    public function test_los_abonos_historicos_sin_despacho_siguen_contando(): void
    {
        $cliente = $this->cliente();
        $engine = app(OrderEngine::class);
        $order = $engine->recomputeTotals($this->pedidoDe($cliente));

        // Un abono como los que existían antes del cambio de flujo.
        Payment::create([
            'company_id' => $this->companyId,
            'order_id' => $order->id,
            'delivery_id' => null,
            'payment_date' => now()->toDateString(),
            'amount' => 90000,
            'payment_method' => 'cash',
            'created_by_user_id' => $this->user->id,
        ]);

        $engine->refreshPaymentStatus($order);
        $order->refresh();

        $this->assertSame('90000.00', $order->paid_amount, 'El histórico debe seguir sumando al pagado.');
        $this->assertCount(1, $order->legacyPayments, 'Y debe listarse aparte como histórico.');
    }

    public function test_la_ficha_del_pedido_muestra_despachos_abonos_y_retenciones(): void
    {
        $cliente = $this->cliente();
        $tax = $this->retencion(2.5);
        $cliente->retentionTaxes()->sync([$tax->id]);

        $engine = app(OrderEngine::class);
        $order = $this->pedidoDe($cliente);
        $engine->syncRetentions($order, $engine->suggestRetentionsFor($cliente, 1000000));
        $order = $engine->recomputeTotals($order->fresh(['items', 'retentions']));

        $delivery = $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 3]],
            'ZZ-REM-9',
        );
        $engine->registerPayment($delivery, ['amount' => 150000, 'payment_method' => 'cash']);

        $company = Company::find($this->companyId);
        $modulos = $company->active_modules ?? [];
        $company->update(['active_modules' => array_values(array_unique([...$modulos, 'order_taking']))]);
        app(CurrentCompany::class)->set($company->refresh());

        $html = $this->get(
            OrderResource::getUrl('view', ['record' => $order])
        )->assertSuccessful()->getContent();

        $company->update(['active_modules' => $modulos]);

        $this->assertStringContainsString('Despachos y abonos', $html);
        $this->assertStringContainsString('Retenciones', $html);
        $this->assertStringNotContainsString(
            'Registrar abono</span>', $html,
            'El abono ya no se registra desde la cabecera del pedido.'
        );

        // Las tablas de los paneles cargan diferido, asi que el despacho y su
        // abono se verifican sobre el componente, no sobre el HTML inicial.
        Livewire::test(
            DeliveriesRelationManager::class,
            [
                'ownerRecord' => $order,
                'pageClass' => ViewOrder::class,
            ],
        )
            ->assertCanSeeTableRecords([$delivery])
            ->assertSee('ZZ-REM-9')
            ->assertTableActionExists('registerPayment');

        Livewire::test(
            PaymentsRelationManager::class,
            [
                'ownerRecord' => $order->refresh(),
                'pageClass' => ViewOrder::class,
            ],
        )->assertSee('ZZ-REM-9');
    }

    public function test_el_pdf_del_pedido_muestra_la_retencion_y_el_neto(): void
    {
        $cliente = $this->cliente();
        $tax = $this->retencion(2.5);
        $cliente->retentionTaxes()->sync([$tax->id]);

        $engine = app(OrderEngine::class);
        $order = $this->pedidoDe($cliente);
        $engine->syncRetentions($order, $engine->suggestRetentionsFor($cliente, 1000000));
        $order = $engine->recomputeTotals($order->fresh(['items', 'retentions']));

        $html = view('order-taking.order-pdf', [
            'order' => $order->load(['items.product', 'customer', 'priceList', 'seller', 'retentions']),
            'company' => Company::find($this->companyId),
        ])->render();

        $this->assertStringContainsString('ZZRF', $html);

        // Columnas del formato que el cliente ya usa con sus compradores. Son
        // literales a proposito: quien lo recibe lleva anos leyendo ese papel,
        // y renombrar una columna «para que se entienda mejor» obliga a volver
        // a explicarlo en cada pedido.
        foreach (['COD', 'DESCRIPCION PRODUCTO', 'PEDIDO<br>CAJAS', 'VALOR CAJA',
            'IMPUESTO', 'COSTO PEDIDO', 'TOTAL CAJAS', 'VALOR ANTES IMPUESTOS'] as $columna) {
            $this->assertStringContainsString($columna, $html, "Falta la columna {$columna}.");
        }

        // La tarifa completa, no recortada a 2 decimales: si dijera "2,5" en un
        // 2.514% la cuenta que ve el cliente no daria. Con coma, como el resto
        // de las cifras del formato.
        $this->assertStringContainsString('2,5%', $html);
        $this->assertStringContainsString('VALOR NETO FACTURA', $html);
        $this->assertStringContainsString('1.165.000', $html);
    }

    /**
     * Caso real: listas importadas que solo traen el precio publico, sin
     * desglose de base e IVA. Antes la retencion salia en cero porque se
     * calculaba sobre el subtotal, que en esas lineas vale 0.
     */
    public function test_la_retencion_usa_el_precio_publico_cuando_la_lista_no_desglosa_iva(): void
    {
        $cliente = $this->cliente();
        $tax = $this->retencion(0.414);
        $cliente->retentionTaxes()->sync([$tax->id]);

        $page = new NewOrder;
        $page->customerId = $cliente->id;

        // Linea sin desglose: base 0, IVA 0, publico 148.800 — como la deja el
        // importador cuando esas columnas vienen vacias en el Excel.
        $page->cart = [[
            'product_id' => 1,
            'code' => 'MG-62',
            'name' => 'BOL COSMIKA KILO',
            'quantity' => 1,
            'price_before_tax' => 0.0,
            'tax_amount' => 0.0,
            'price_at_public' => 148800.0,
        ]];
        $page->loadRetentionsForCustomer();

        $this->assertSame(148800.0, $page->taxableBase,
            'Sin desglose, el precio publico ES la base gravable.');

        $retenciones = $page->retentions;
        $this->assertCount(1, $retenciones);
        $this->assertSame(148800.0, (float) $retenciones[0]['base_amount']);
        $this->assertSame(616.03, (float) $retenciones[0]['amount'],
            'El 0.414% de 148.800 son 616,03.');
    }

    /** Con desglose real manda la base, no el precio publico. */
    public function test_la_retencion_usa_la_base_cuando_la_lista_si_desglosa_iva(): void
    {
        $cliente = $this->cliente();
        $tax = $this->retencion(2.5);
        $cliente->retentionTaxes()->sync([$tax->id]);

        $page = new NewOrder;
        $page->customerId = $cliente->id;
        $page->cart = [[
            'product_id' => 1,
            'code' => 'ZZ',
            'name' => 'ZZ Producto',
            'quantity' => 10,
            'price_before_tax' => 100000.0,
            'tax_amount' => 19000.0,
            'price_at_public' => 119000.0,
        ]];
        $page->loadRetentionsForCustomer();

        $this->assertSame(1000000.0, $page->taxableBase,
            'Con desglose, la base excluye el IVA.');
        $this->assertSame(25000.0, (float) $page->retentions[0]['amount']);
    }

    public function test_el_informe_muestra_cliente_cantidades_y_detalle(): void
    {
        $cliente = $this->cliente();
        $engine = app(OrderEngine::class);
        $order = $engine->recomputeTotals($this->pedidoDe($cliente));

        // 4 de 10 despachadas: quedan 6 pendientes.
        $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 4]],
        );

        $company = Company::find($this->companyId);
        $modulos = $company->active_modules ?? [];
        $company->update(['active_modules' => array_values(array_unique([...$modulos, 'order_taking']))]);
        app(CurrentCompany::class)->set($company->refresh());

        $page = Livewire::test(DeliveryReport::class);

        $company->update(['active_modules' => $modulos]);

        $page->assertCanSeeTableRecords([$order])
            ->assertSee('ZZ Cliente Retenedor');

        $fila = $page->instance()->getTableRecords()->firstWhere('id', $order->id);
        $this->assertSame(10.0, (float) $fila->qty_ordered);
        $this->assertSame(4.0, (float) $fila->qty_delivered);

        $lineas = $page->instance()->lineasDe($order->id);
        $this->assertSame(6.0, $lineas[0]['pendiente'], 'De 10 pedidas y 4 despachadas quedan 6.');

        // El modal del detalle: que compile y traiga las cifras de la linea.
        $modal = view('filament.app.pages.order-taking.delivery-report-detail', ['lineas' => $lineas])->render();
        $this->assertStringContainsString('ZZ Producto', $modal);
        $this->assertStringContainsString('6,00', $modal);
        $this->assertStringNotContainsString('#f1f5f9', $modal,
            'Nada de fondos claros fijos: el modal se pinta sobre el tema del usuario.');
    }

    public function test_el_informe_conserva_el_nombre_del_cliente_borrado(): void
    {
        $cliente = $this->cliente();
        $engine = app(OrderEngine::class);
        $order = $engine->recomputeTotals($this->pedidoDe($cliente));

        // El cliente se da de baja despues de tomarle el pedido.
        $cliente->delete();

        $company = Company::find($this->companyId);
        $modulos = $company->active_modules ?? [];
        $company->update(['active_modules' => array_values(array_unique([...$modulos, 'order_taking']))]);
        app(CurrentCompany::class)->set($company->refresh());

        $page = Livewire::test(DeliveryReport::class);

        $company->update(['active_modules' => $modulos]);

        $page->assertSee('ZZ Cliente Retenedor');
    }

    public function test_el_estado_de_despacho_queda_al_dia_tras_despachar_todo(): void
    {
        $cliente = $this->cliente();
        $engine = app(OrderEngine::class);
        $order = $engine->recomputeTotals($this->pedidoDe($cliente));

        // Despacho completo: las 10 unidades de la unica linea.
        $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 10]],
        );

        $order->refresh();

        $this->assertSame('delivered', $order->delivery_status,
            'Despachado todo, el estado no puede seguir en pendiente.');
        $this->assertSame(Order::STATUS_FULLY_DELIVERED, $order->status);
    }

    /**
     * Lo que arregla la migracion de correccion: pedidos que quedaron con el
     * estado viejo aunque sus lineas si tienen la cantidad entregada.
     */
    public function test_recalcular_corrige_un_estado_de_despacho_que_quedo_mal(): void
    {
        $cliente = $this->cliente();
        $engine = app(OrderEngine::class);
        $order = $engine->recomputeTotals($this->pedidoDe($cliente));

        // Se simula el dano: linea entregada por completo, cabecera en pendiente.
        $order->items->first()->update(['quantity_delivered' => 10]);
        $order->update(['delivery_status' => 'pending', 'status' => Order::STATUS_CONFIRMED]);

        $engine->refreshDeliveryStatus($order->fresh());

        $order->refresh();
        $this->assertSame('delivered', $order->delivery_status);
        $this->assertSame(Order::STATUS_FULLY_DELIVERED, $order->status);
    }

    /**
     * El PDF de verdad, no solo el HTML: dompdf revienta con CSS que la vista
     * acepta sin chistar.
     */
    public function test_el_pdf_del_pedido_se_genera_sin_reventar(): void
    {
        $cliente = $this->cliente();
        $tax = $this->retencion(2.5);
        $cliente->retentionTaxes()->sync([$tax->id]);

        $engine = app(OrderEngine::class);
        $order = $this->pedidoDe($cliente);
        $engine->syncRetentions($order, $engine->suggestRetentionsFor($cliente, 1000000));
        $order = $engine->recomputeTotals($order->fresh(['items', 'retentions']));

        $pdf = Pdf::loadView('order-taking.order-pdf', [
            'order' => $order->load(['items.product', 'customer', 'priceList', 'seller', 'retentions']),
            'company' => Company::find($this->companyId),
        ])->output();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf), 'Un PDF de una pagina real pesa mas que esto.');
    }

    /**
     * El IVA de la linea, no el unitario: si no, total != subtotal + IVA en
     * cuanto la cantidad pase de 1.
     */
    public function test_el_iva_del_pedido_cuadra_con_el_total(): void
    {
        $cliente = $this->cliente();

        $page = new NewOrder;
        $page->customerId = $cliente->id;
        $page->orderDate = now()->toDateString();
        $page->cart = [[
            'product_id' => $this->producto()->id,
            'code' => 'ZZ-PROD-1',
            'name' => 'ZZ Producto',
            'quantity' => 4,
            'price_before_tax' => 128160.0,
            'tax_amount' => 24350.4,   // por unidad, como viene de la lista
            'price_at_public' => 152510.4,
        ]];
        $page->saveOrder();

        $order = Order::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->latest('id')->firstOrFail();

        $this->limpiar[] = function () use ($order) {
            OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->forceDelete();
            Order::withoutGlobalScopes()->whereKey($order->id)->forceDelete();
        };

        $this->assertSame('512640.00', $order->subtotal, '4 × 128.160');
        $this->assertSame('97401.60', $order->tax_total, '4 × 24.350,40 — no el unitario');
        $this->assertSame('610041.60', $order->total);
        $this->assertSame(
            round((float) $order->subtotal + (float) $order->tax_total, 2),
            round((float) $order->total, 2),
            'El total tiene que ser subtotal + IVA.'
        );
    }

    public function test_el_listado_de_pedidos_traduce_estados_y_busca_por_numero(): void
    {
        $cliente = $this->cliente();
        $engine = app(OrderEngine::class);
        $order = $engine->recomputeTotals($this->pedidoDe($cliente));

        // Despacho completo: el estado pasa a "Entregado".
        $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 10]],
        );

        $company = Company::find($this->companyId);
        $modulos = $company->active_modules ?? [];
        $company->update(['active_modules' => array_values(array_unique([...$modulos, 'order_taking']))]);
        app(CurrentCompany::class)->set($company->refresh());
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $page = Livewire::test(ListOrders::class);

        $company->update(['active_modules' => $modulos]);

        $page->assertCanSeeTableRecords([$order])
            // 'fully_delivered' sigue apareciendo en el HTML como value= de
            // las opciones del filtro, que es correcto; lo que importa es que
            // la celda muestre la etiqueta.
            ->assertSee('Entregado')
            ->assertSee($order->fullNumber());

        // Buscar por el numero como se ve en pantalla tiene que encontrarlo.
        $page->set('tableSearch', $order->fullNumber())
            ->assertCanSeeTableRecords([$order]);
    }


    /**
     * Un despacho se factura por lo que salió en él.
     *
     * Antes se facturaba el pedido completo de una vez. Desde que cada
     * despacho da su propia factura, lo que se cobra es la remisión: es lo
     * que el cliente recibió, y es contra eso que paga.
     */
    public function test_un_despacho_se_convierte_en_factura(): void
    {
        $cliente = $this->cliente();
        $tax = $this->retencion(2.5);
        $cliente->retentionTaxes()->sync([$tax->id]);
        $sede = $this->sedeConResolucion();

        $engine = app(OrderEngine::class);
        $order = $this->pedidoDe($cliente);
        $order->update(['location_id' => $sede->id]);
        $engine->syncRetentions($order, $engine->suggestRetentionsFor($cliente, 1000000));
        $order = $engine->recomputeTotals($order->fresh(['items', 'retentions']));

        $delivery = $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 10]],
            'ZZ-REM-F',
        );
        $engine->registerPayment($delivery, ['amount' => 400000, 'payment_method' => 'cash']);

        $factura = $engine->invoiceDelivery($delivery->fresh(), 'pos', allowNegativeStock: true);

        // Los cobros ANTES que la factura: borrarla sola deja el pago
        // apuntando a una factura que ya no existe, y eso no lo nota nadie
        // porque un pago huerfano no sale en ninguna pantalla.
        $this->limpiar[] = function () use ($factura) {
            DB::table('payments')
                ->where('paymentable_type', SaleInvoice::class)
                ->where('paymentable_id', $factura->id)
                ->delete();

            SaleInvoice::withoutGlobalScopes()->whereKey($factura->id)->forceDelete();
        };

        $this->assertSame('1000000.00', $factura->subtotal);
        $this->assertSame('190000.00', $factura->tax_total);
        $this->assertSame('1190000.00', $factura->total);
        $this->assertSame('25000.00', $factura->retention_total, 'La retencion del pedido pasa a la factura.');
        $this->assertSame('1165000.00', $factura->net_payable);

        $this->assertSame('400000.00', $factura->fresh()->paid_amount,
            'Los abonos del despacho pasan como pagos de la factura.');

        // La factura cuelga del DESPACHO, no del pedido: es lo que impide
        // facturar dos veces la misma remision.
        $this->assertSame($factura->id, $delivery->fresh()->sale_invoice_id);
        $this->assertTrue($delivery->fresh()->isInvoiced());
        $this->assertNull($order->fresh()->sale_invoice_id,
            'El pedido completo ya no se factura de una sola vez.');
    }

    /**
     * Un despacho parcial SÍ se factura.
     *
     * Antes se exigía que el pedido hubiera salido completo, y eso dejaba al
     * cliente esperando su factura por lo que ya había recibido mientras el
     * resto seguía en la bodega. Ahora se cobra lo despachado.
     */
    public function test_un_despacho_parcial_tambien_se_factura(): void
    {
        $cliente = $this->cliente();
        $sede = $this->sedeConResolucion();

        $engine = app(OrderEngine::class);
        $order = $engine->recomputeTotals($this->pedidoDe($cliente));
        $order->update(['location_id' => $sede->id]);

        // Solo 4 de 10: queda mercancia en la bodega.
        $delivery = $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 4]],
        );

        $factura = $engine->invoiceDelivery($delivery->fresh(), 'pos', allowNegativeStock: true);

        $this->limpiar[] = function () use ($factura) {
            DB::table('payments')
                ->where('paymentable_type', SaleInvoice::class)
                ->where('paymentable_id', $factura->id)
                ->delete();

            SaleInvoice::withoutGlobalScopes()->whereKey($factura->id)->forceDelete();
        };

        $this->assertSame('400000.00', $factura->subtotal,
            'Se cobra lo que salio: 4 unidades, no las 10 del pedido.');
        $this->assertSame('partial', $order->fresh()->delivery_status,
            'El pedido sigue con pendientes aunque ya se haya facturado lo despachado.');
    }

    /** La misma remisión no se factura dos veces. */
    public function test_un_despacho_no_se_puede_facturar_dos_veces(): void
    {
        $cliente = $this->cliente();
        $sede = $this->sedeConResolucion();

        $engine = app(OrderEngine::class);
        $order = $engine->recomputeTotals($this->pedidoDe($cliente));
        $order->update(['location_id' => $sede->id]);
        $delivery = $engine->registerDelivery(
            $order,
            [['order_item_id' => $order->items->first()->id, 'quantity' => 10]],
        );

        $factura = $engine->invoiceDelivery($delivery->fresh(), 'pos', allowNegativeStock: true);

        $this->limpiar[] = function () use ($factura) {
            DB::table('payments')
                ->where('paymentable_type', SaleInvoice::class)
                ->where('paymentable_id', $factura->id)
                ->delete();

            SaleInvoice::withoutGlobalScopes()->whereKey($factura->id)->forceDelete();
        };

        $this->expectExceptionMessageMatches('/ya se facturó/i');
        $engine->invoiceDelivery($delivery->fresh(), 'pos', allowNegativeStock: true);
    }
}
