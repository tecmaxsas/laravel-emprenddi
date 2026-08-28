<?php

namespace Tests\Feature;

use App\Filament\App\Pages\OrderTaking\NewOrder;
use App\Filament\App\Resources\OrderTaking\OrderResource;
use App\Filament\App\Resources\OrderTaking\OrderResource\Pages\ViewOrder;
use App\Filament\App\Resources\OrderTaking\OrderResource\RelationManagers\DeliveriesRelationManager;
use App\Filament\App\Resources\OrderTaking\OrderResource\RelationManagers\PaymentsRelationManager;
use App\Models\Company;
use App\Models\OrderTaking\Delivery;
use App\Models\OrderTaking\DeliveryItem;
use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\OrderItem;
use App\Models\OrderTaking\OrderRetention;
use App\Models\OrderTaking\Payment;
use App\Models\Product;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\OrderTaking\OrderEngine;
use App\Support\CurrentCompany;
use Livewire\Livewire;
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
    private User $user;

    private int $companyId;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->whereNotNull('company_id')->firstOrFail();
        $this->companyId = (int) $this->user->company_id;
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

    private function cliente(): ThirdParty
    {
        $cliente = ThirdParty::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'document_number' => 'ZZ-RET-001'],
            [
                'person_type' => 'juridica',
                'document_type' => 'nit',
                'name' => 'ZZ Cliente Retenedor',
                'is_customer' => true,
                'active' => true,
            ]
        );

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()->whereKey($cliente->id)->forceDelete();

        return $cliente;
    }

    private function retencion(float $rate = 2.5): Tax
    {
        $tax = Tax::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'code' => 'ZZRF'],
            [
                'name' => 'ZZ ReteFuente',
                'type' => 'income_withholding',
                'rate' => $rate,
                'applies_to' => 'sale',
                'is_active' => true,
            ]
        );

        $this->limpiar[] = fn () => Tax::withoutGlobalScopes()->whereKey($tax->id)->forceDelete();

        return $tax;
    }

    /** Pedido de $1.000.000 + IVA 19% = $1.190.000. */
    private function pedidoDe(ThirdParty $cliente): Order
    {
        $engine = app(OrderEngine::class);

        // Antes que el pedido: tearDown limpia en orden inverso, asi el pedido
        // se borra primero y el producto queda sin referencias.
        $producto = Product::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'code' => 'ZZ-PROD-1'],
            ['name' => 'ZZ Producto']
        );

        $this->limpiar[] = fn () => Product::withoutGlobalScopes()
            ->whereKey($producto->id)->forceDelete();

        $order = Order::create([
            'company_id' => $this->companyId,
            'prefix' => 'ZZP',
            'number' => $engine->reserveNumber($this->companyId, 'ZZP'),
            'third_party_id' => $cliente->id,
            'seller_user_id' => $this->user->id,
            'created_by_user_id' => $this->user->id,
            'order_date' => now()->toDateString(),
            'status' => Order::STATUS_CONFIRMED,
            'delivery_status' => 'pending',
            'payment_status' => 'pendiente',
        ]);

        // El borrado del pedido no arrastra a sus hijos, asi que se limpian a mano
        // y en orden: abonos y despachos antes que las lineas.
        $this->limpiar[] = function () use ($order) {
            Payment::withoutGlobalScopes()->where('order_id', $order->id)->forceDelete();
            DeliveryItem::withoutGlobalScopes()
                ->whereIn('delivery_id', Delivery::withoutGlobalScopes()->where('order_id', $order->id)->pluck('id'))
                ->forceDelete();
            Delivery::withoutGlobalScopes()->where('order_id', $order->id)->forceDelete();
            OrderRetention::withoutGlobalScopes()->where('order_id', $order->id)->forceDelete();
            OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->forceDelete();
            Order::withoutGlobalScopes()->whereKey($order->id)->forceDelete();
        };

        OrderItem::create([
            'company_id' => $this->companyId,
            'order_id' => $order->id,
            'product_id' => $producto->id,
            'line_number' => 1,
            'description' => 'ZZ Producto',
            'quantity_ordered' => 10,
            'quantity_delivered' => 0,
            'unit_price_before_tax' => 100000,
            'tax_rate' => 19,
            'tax_amount' => 19000,
            'unit_price_at_public' => 119000,
            'subtotal' => 1000000,
            'total' => 1190000,
        ]);

        return $order->fresh(['items']);
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
        // La tarifa completa, no recortada a 2 decimales: si dijera "2.5" en un
        // 2.514% la cuenta que ve el cliente no daria.
        $this->assertStringContainsString('(2.5%)', $html);
        $this->assertStringContainsString('NETO A PAGAR', $html);
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
}
