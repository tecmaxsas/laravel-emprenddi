<?php

namespace Tests\Feature;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Inventory\InventoryEngine;
use App\Services\Purchases\PurchaseInvoiceEngine;
use App\Support\CurrentCompany;
use App\Support\PaymentAccountResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Corregir una factura de compra ya contabilizada.
 *
 * Hasta ahora había dos salidas: anularla y rehacerla, o dejarla mal. Y
 * anular exige que no haya pagos —cosa que una compra de contado siempre
 * tiene— así que en la práctica se quedaba mal.
 *
 * Es el mismo remedio que ya tienen las facturas de venta, y la reversa no se
 * copió: se extrajo la que usaba la anulación. Dos reversas que empiezan
 * iguales se separan a la primera corrección que alguien haga en una sola.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CorregirCompraTest extends TestCase
{
    private Company $empresa;

    private Location $sede;

    private ThirdParty $proveedor;

    private Product $producto;

    private CashRegisterSession $turno;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->empresa);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();

        $this->prepararCatalogo();
        $this->turno = $this->turnoAbierto();
    }

    protected function tearDown(): void
    {
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

    /** Vuelve a borrador, que es lo que permite corregirla. */
    public function test_la_compra_vuelve_a_borrador(): void
    {
        $factura = $this->compraPagada(500000);

        $editable = app(PurchaseInvoiceEngine::class)->prepararParaEditar($factura);

        $this->assertTrue($editable->isDraft());
        $this->assertEqualsWithDelta(0, (float) $editable->paid_amount, 0.01);
        $this->assertNull($editable->journal_entry_id);
    }

    /**
     * El inventario se queda donde está: la mercancía no se ha movido.
     *
     * Antes salía del stock al abrir la corrección y volvía a entrar al
     * contabilizar de nuevo. El resultado final era el mismo, pero el paso
     * intermedio no: si parte de la compra ya se había vendido, ese bajón
     * dejaba existencias en negativo y el motor de inventario lo rechazaba
     * —bloqueando la factura ENTERA por culpa de una línea—. Y si alguien
     * abandonaba la corrección a medias, la mercancía quedaba desaparecida
     * del stock aunque siguiera en el estante.
     *
     * Ahora el inventario se lleva a la cifra nueva moviendo solo la
     * diferencia, al volver a contabilizar. Ver CorregirCompraConStockVendidoTest.
     */
    public function test_el_inventario_se_queda_mientras_se_corrige(): void
    {
        $factura = $this->compraPagada(500000, conInventario: true);

        $conCompra = $this->stock();

        app(PurchaseInvoiceEngine::class)->prepararParaEditar($factura);

        $this->assertEqualsWithDelta($conCompra, $this->stock(), 0.001,
            'La mercancía sigue en la bodega mientras se corrige la factura.');
    }

    /**
     * Los pagos se borran.
     *
     * Con el total cambiado, un pago viejo deja el arqueo del turno mostrando
     * una cifra que ya no corresponde a nada.
     */
    public function test_los_pagos_se_borran(): void
    {
        $factura = $this->compraPagada(500000);

        $this->assertSame(1, $factura->payments()->count());

        app(PurchaseInvoiceEngine::class)->prepararParaEditar($factura);

        $this->assertSame(0, $factura->fresh()->payments()->count());
    }

    /** Corregida y vuelta a contabilizar, los totales son los nuevos. */
    public function test_se_corrige_y_se_vuelve_a_contabilizar(): void
    {
        $factura = $this->compraPagada(500000);

        app(PurchaseInvoiceEngine::class)->prepararParaEditar($factura);

        $factura->lines()->first()->update([
            'unit_cost' => 300000,
            'subtotal' => 300000,
            'total' => 300000,
        ]);
        $factura->update(['subtotal' => 300000, 'total' => 300000, 'net_payable' => 300000]);

        $corregida = app(PurchaseInvoiceEngine::class)->post($factura->fresh(['lines']));

        $this->assertTrue($corregida->isPosted());
        $this->assertEqualsWithDelta(300000, (float) $corregida->total, 0.01);
    }

    /**
     * No se corrige si su pago quedó en un turno cerrado.
     *
     * Ese arqueo lo firmó alguien.
     */
    public function test_no_se_corrige_con_el_turno_cerrado(): void
    {
        $factura = $this->compraPagada(300000);

        DB::table('cash_register_sessions')->where('id', $this->turno->id)
            ->update(['status' => 'closed', 'closed_at' => now()]);

        $motivo = app(PurchaseInvoiceEngine::class)->porQueNoSePuedeEditar($factura->fresh(['lines']));

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('turno de caja ya cerrado', $motivo);
    }

    /** Un borrador ya es editable. */
    public function test_un_borrador_ya_es_editable(): void
    {
        $factura = $this->borrador(100000);

        $this->assertNull(app(PurchaseInvoiceEngine::class)->porQueNoSePuedeEditar($factura));
    }

    // --------------------------------------------------------- auxiliares

    private function stock(): float
    {
        return app(InventoryEngine::class)->currentStock($this->producto->id, $this->sede->id);
    }

    private function borrador(float $total, bool $conInventario = false): PurchaseInvoice
    {
        $producto = $conInventario ? $this->productoConStock() : $this->producto;

        $factura = PurchaseInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->proveedor->id,
            'prefix' => 'ZZCOR',
            'number' => random_int(100000, 999999),
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => 'draft',
            'payment_status' => 'pendiente',
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
        ]);

        PurchaseInvoiceLine::withoutGlobalScopes()->create([
            'purchase_invoice_id' => $factura->id,
            'line_number' => 1,
            'product_id' => $producto->id,
            'description' => $producto->name,
            'quantity' => 1,
            'unit_cost' => $total,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $this->limpiar[] = function () use ($factura) {
            $asientos = JournalEntry::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->empresa->id)
                ->where(fn ($q) => $q->where('reference', 'like', '%ZZCOR%')
                    ->orWhere('description', 'like', '%ZZCOR%'))
                ->pluck('id');
            DB::table('payments')->where('paymentable_id', $factura->id)
                ->where('paymentable_type', PurchaseInvoice::class)->delete();
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('inventory_movements')->where('reference_id', $factura->id)
                ->where('reference_type', PurchaseInvoice::class)->delete();
            DB::table('purchase_invoice_lines')->where('purchase_invoice_id', $factura->id)->delete();
            DB::table('purchase_invoices')->where('id', $factura->id)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
        };

        return $factura->fresh(['lines']);
    }

    private function compraPagada(float $total, bool $conInventario = false): PurchaseInvoice
    {
        $factura = $this->borrador($total, $conInventario);

        $posteada = app(PurchaseInvoiceEngine::class)->post($factura->fresh(['lines']));

        app(PurchaseInvoiceEngine::class)->addPayment($posteada->fresh(), [
            'amount' => $total,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'account_id' => PaymentAccountResolver::forMethod('cash'),
        ]);

        return $posteada->fresh(['lines']);
    }

    private function productoConStock(): Product
    {
        return $this->producto;
    }

    private function turnoAbierto(): CashRegisterSession
    {
        $previos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('status', 'open')
            ->pluck('id');

        if ($previos->isNotEmpty()) {
            DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => 'closed', 'closed_at' => now()]);

            $this->limpiar[] = fn () => DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => 'open', 'closed_at' => null]);
        }

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => auth()->id(),
            'status' => 'open',
            'opened_at' => now(),
            'opening_amount' => 3000000,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        return $turno;
    }

    private function prepararCatalogo(): void
    {
        $this->proveedor = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'person_type' => 'juridica',
            'document_type' => 'nit',
            'document_number' => 'ZZCOR'.random_int(100000, 999999),
            'name' => 'ZZCOR Proveedor correccion',
            'is_supplier' => true,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZCOR'.random_int(10000, 99999),
            'name' => 'ZZCOR Producto correccion',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => true,
            'is_purchasable' => true,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            DB::table('inventory_movements')->where('product_id', $this->producto->id)->delete();
            DB::table('product_locations')->where('product_id', $this->producto->id)->delete();
            Product::withoutGlobalScopes()->whereKey($this->producto->id)->forceDelete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->proveedor->id)->forceDelete();
        };
    }
}
