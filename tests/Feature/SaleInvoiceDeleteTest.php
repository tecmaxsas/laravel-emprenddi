<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Auth\PermissionsCatalog;
use App\Services\Inventory\InventoryEngine;
use App\Services\Sales\SaleInvoiceDeleter;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Borrar una factura POS.
 *
 * El riesgo de esta función no es que falle: es que funcione a medias. Una
 * factura que desaparece de la lista pero deja el inventario descontado y el
 * asiento puesto es peor que no poder borrarla, porque el descuadre aparece
 * semanas después y nadie lo relaciona.
 *
 * Por eso casi todas estas pruebas miden lo mismo desde ángulos distintos:
 * **que el sistema quede exactamente como antes de la venta**.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class SaleInvoiceDeleteTest extends TestCase
{
    private Company $company;

    private Location $sede;

    private Product $producto;

    private ThirdParty $cliente;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->orderBy('id')->firstOrFail();

        $this->prepararCatalogo();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** El inventario vuelve exactamente a donde estaba. */
    public function test_el_inventario_vuelve_a_su_sitio(): void
    {
        $inventario = app(InventoryEngine::class);
        $antes = $inventario->currentStock($this->producto->id, $this->sede->id);

        $factura = $this->ventaPosContabilizada(cantidad: 3);

        $this->assertEqualsWithDelta($antes - 3,
            $inventario->currentStock($this->producto->id, $this->sede->id), 0.01,
            'La venta debió descontar tres unidades.');

        app(SaleInvoiceDeleter::class)->delete($factura, 'prueba');

        $this->assertEqualsWithDelta($antes,
            $inventario->currentStock($this->producto->id, $this->sede->id), 0.01,
            'Después de borrar, el stock tiene que ser el de antes de vender.');
    }

    /** Los asientos quedan neteados: lo que sumó la venta lo resta la reversa. */
    public function test_los_asientos_quedan_neteados(): void
    {
        $factura = $this->ventaPosContabilizada();

        app(SaleInvoiceDeleter::class)->delete($factura, 'prueba');

        $asientos = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where(fn ($q) => $q->where('reference', $factura->fullNumber())
                ->orWhere('reference', 'ANUL-'.$factura->fullNumber())
                ->orWhere('reference', 'BORR-'.$factura->fullNumber()))
            ->with('lines')
            ->get();

        $this->assertGreaterThanOrEqual(2, $asientos->count(),
            'Tiene que haber al menos el asiento de la venta y su reversa.');

        // El efecto neto sobre cada cuenta debe ser cero: si no, la venta dejó
        // saldo en alguna parte.
        $porCuenta = [];

        foreach ($asientos as $asiento) {
            foreach ($asiento->lines as $linea) {
                $porCuenta[$linea->account_id] =
                    ($porCuenta[$linea->account_id] ?? 0)
                    + (float) $linea->debit - (float) $linea->credit;
            }
        }

        foreach ($porCuenta as $cuenta => $saldo) {
            $this->assertEqualsWithDelta(0, $saldo, 0.01,
                "La cuenta {$cuenta} quedó con saldo después de borrar la factura.");
        }
    }

    /**
     * El caso que hace útil la función: una venta POS está pagada, y la
     * anulación normal se niega a tocarla.
     */
    public function test_borra_una_factura_pagada(): void
    {
        $factura = $this->ventaPosContabilizada();
        $this->pagar($factura);

        $factura->refresh();
        $this->assertGreaterThan(0, (float) $factura->paid_amount);

        // La anulación se rinde aquí: es justo el hueco que esto viene a llenar.
        try {
            app(SaleInvoiceEngine::class)->cancel($factura);
            $this->fail('La anulación debería negarse con pagos registrados.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pagos', $e->getMessage());
        }

        app(SaleInvoiceDeleter::class)->delete($factura->fresh(), 'prueba');

        $this->assertSame(0, Payment::withoutGlobalScope(CompanyScope::class)
            ->where('paymentable_type', SaleInvoice::class)
            ->where('paymentable_id', $factura->id)
            ->count(), 'Los pagos tienen que desaparecer con la factura.');

        // El recibo de caja tampoco puede seguir contando como ingreso.
        $this->assertEqualsWithDelta(0,
            (float) SaleInvoice::withoutGlobalScope(CompanyScope::class)
                ->withTrashed()->find($factura->id)->paid_amount,
            0.01, 'La factura borrada no puede quedar marcada como pagada.');
    }

    /** La factura desaparece de los listados pero la fila se queda. */
    public function test_desaparece_de_los_listados_pero_no_de_la_base(): void
    {
        $factura = $this->ventaPosContabilizada();
        $id = $factura->id;

        app(SaleInvoiceDeleter::class)->delete($factura, 'prueba');

        // Ojo con `withoutGlobalScopes()` a secas: también quita el de borrado
        // suave, así que devolvería la factura borrada y la prueba pasaría sin
        // comprobar nada. Aquí se quita solo el de empresa.
        $this->assertNull(
            SaleInvoice::withoutGlobalScope(CompanyScope::class)->find($id),
            'No puede seguir apareciendo en las consultas normales.');

        $this->assertNotNull(
            SaleInvoice::withoutGlobalScope(CompanyScope::class)->withTrashed()->find($id),
            'Un consecutivo que desaparece sin rastro es un hueco que no se le puede explicar a la DIAN.');
    }

    /** El motivo queda escrito en la factura borrada. */
    public function test_queda_registrado_quien_borro_y_por_que(): void
    {
        $factura = $this->ventaPosContabilizada();

        app(SaleInvoiceDeleter::class)->delete($factura, 'Cobrada dos veces por error');

        $borrada = SaleInvoice::withoutGlobalScopes()->withTrashed()->find($factura->id);

        $this->assertStringContainsString('Cobrada dos veces por error', $borrada->notes);
        $this->assertStringContainsString('Borrada el', $borrada->notes);
    }

    // ------------------------------------------------------------ guardas

    /** Una factura electrónica no se borra nunca. */
    public function test_una_factura_electronica_no_se_borra(): void
    {
        $factura = $this->ventaPosContabilizada();
        $factura->update(['invoice_kind' => 'electronic']);

        $razon = app(SaleInvoiceDeleter::class)->motivoParaNoBorrar($factura->fresh());

        $this->assertNotNull($razon);
        $this->assertStringContainsString('nota crédito', $razon);

        $this->expectException(RuntimeException::class);
        app(SaleInvoiceDeleter::class)->delete($factura->fresh(), 'prueba');
    }

    /** Ni una POS que por algún motivo llegó a la DIAN. */
    public function test_una_factura_enviada_a_la_dian_no_se_borra(): void
    {
        $factura = $this->ventaPosContabilizada();
        $factura->update(['dian_status' => SaleInvoice::DIAN_ACCEPTED]);

        $this->assertStringContainsString('DIAN',
            app(SaleInvoiceDeleter::class)->motivoParaNoBorrar($factura->fresh()));
    }

    /**
     * Ni una de un turno de caja ya cerrado: el cuadre se firmó con esa venta
     * adentro y quitarla lo deja mintiendo para siempre.
     */
    public function test_una_venta_de_un_turno_cerrado_no_se_borra(): void
    {
        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => auth()->id(),
            'status' => CashRegisterSession::STATUS_CLOSED,
            'opened_at' => now()->subHours(8),
            'closed_at' => now()->subHour(),
            'opening_amount' => 100000,
        ]);
        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        $factura = $this->ventaPosContabilizada();
        $factura->update(['cash_register_session_id' => $turno->id]);

        $razon = app(SaleInvoiceDeleter::class)->motivoParaNoBorrar($factura->fresh());

        $this->assertNotNull($razon);
        $this->assertStringContainsString('turno de caja', $razon);
    }

    /** Con el turno abierto sí, porque sus totales se recalculan solos. */
    public function test_una_venta_de_un_turno_abierto_si_se_borra(): void
    {
        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => auth()->id(),
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now()->subHour(),
            'opening_amount' => 100000,
        ]);
        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();

        $factura = $this->ventaPosContabilizada();
        $factura->update(['cash_register_session_id' => $turno->id]);
        $this->pagar($factura->fresh());

        $antes = $turno->computeRunningTotals();
        $this->assertGreaterThan(0, $antes['total_sales']);

        $this->assertNull(app(SaleInvoiceDeleter::class)->motivoParaNoBorrar($factura->fresh()));

        app(SaleInvoiceDeleter::class)->delete($factura->fresh(), 'prueba');

        $despues = $turno->fresh()->computeRunningTotals();

        $this->assertEqualsWithDelta(0, $despues['total_sales'], 0.01,
            'Los totales del turno abierto se recalculan de las facturas vivas.');
    }

    /** Borrar dos veces no vuelve a mover nada. */
    public function test_no_se_puede_borrar_dos_veces(): void
    {
        $factura = $this->ventaPosContabilizada();
        app(SaleInvoiceDeleter::class)->delete($factura, 'prueba');

        $borrada = SaleInvoice::withoutGlobalScopes()->withTrashed()->find($factura->id);

        $this->assertStringContainsString('ya está borrada',
            app(SaleInvoiceDeleter::class)->motivoParaNoBorrar($borrada));
    }

    /** El permiso es solo del administrador. */
    public function test_el_permiso_es_solo_del_administrador(): void
    {
        $catalogo = PermissionsCatalog::class;

        $this->assertContains('sales.delete', $catalogo::all());
        $this->assertContains('sales.delete', $catalogo::defaultForRole('admin'));

        foreach (['manager', 'cashier', 'seller', 'accountant'] as $rol) {
            $this->assertNotContains('sales.delete', $catalogo::defaultForRole($rol),
                "El rol {$rol} no debería poder borrar ventas.");
        }
    }

    // --------------------------------------------------------- auxiliares

    private function ventaPosContabilizada(float $cantidad = 2): SaleInvoice
    {
        $total = 100000 * $cantidad;

        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZDEL',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'pos',
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => SaleInvoice::STATUS_DRAFT,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            'subtotal' => $total,
            'total' => $total,
            'net_payable' => $total,
        ]);

        SaleInvoiceLine::withoutGlobalScopes()->create([
            'sale_invoice_id' => $factura->id,
            'line_number' => 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => $cantidad,
            'unit_price' => 100000,
            'cost_at_sale' => 50000,
            'subtotal' => $total,
            'total' => $total,
        ]);

        $this->limpiar[] = function () use ($factura) {
            $asientos = JournalEntry::withoutGlobalScopes()
                ->where('company_id', $this->company->id)
                ->where(fn ($q) => $q->where('reference', 'like', '%ZZDEL%'))
                ->pluck('id');
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
            DB::table('payments')->where('paymentable_id', $factura->id)
                ->where('paymentable_type', SaleInvoice::class)->delete();
            DB::table('inventory_movements')->where('reference_id', $factura->id)->delete();
            DB::table('sale_invoice_lines')->where('sale_invoice_id', $factura->id)->delete();
            DB::table('sale_invoices')->where('id', $factura->id)->delete();
        };

        return app(SaleInvoiceEngine::class)->post($factura->fresh(['lines']), allowNegativeStock: true);
    }

    private function pagar(SaleInvoice $factura): void
    {
        $cuenta = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('accepts_movements', true)
            ->where('code', 'like', '1105%')
            ->value('id')
            ?? Account::withoutGlobalScopes()
                ->where('company_id', $this->company->id)
                ->where('accepts_movements', true)
                ->orderBy('code')
                ->value('id');

        app(SaleInvoiceEngine::class)->addPayment($factura, [
            'amount' => (float) $factura->net_payable,
            'date' => now()->toDateString(),
            'payment_method' => 'cash',
            'account_id' => $cuenta,
        ]);
    }

    private function prepararCatalogo(): void
    {
        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ CLIENTE BORRADO',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZDEL'.random_int(10000, 99999),
            'name' => 'ZZ Producto a borrar',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => true,
            'is_sellable' => true,
            'default_sale_price' => 100000,
            'default_purchase_price' => 50000,
            'active' => true,
        ]);

        $this->limpiar[] = function () {
            DB::table('inventory_movements')->where('product_id', $this->producto->id)->delete();
            DB::table('product_locations')->where('product_id', $this->producto->id)->delete();
            Product::withoutGlobalScopes()->whereKey($this->producto->id)->forceDelete();
            ThirdParty::withoutGlobalScopes()->whereKey($this->cliente->id)->forceDelete();
        };
    }
}
