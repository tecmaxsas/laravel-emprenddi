<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\CreditDebitNoteLine;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Sales\CreditDebitNoteEngine;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contabilizar una nota crédito.
 *
 * En producción esto reventaba con un «Check violation 23514» crudo en pantalla:
 * el motor escribe `type='credit_note'` y ese valor no estaba en la lista
 * permitida de `journal_entries.type`. La nota se quedaba en borrador para
 * siempre, y como el botón de enviar a la DIAN solo aparece sobre notas
 * contabilizadas, tampoco había manera de transmitirla.
 *
 * La prueba que impide que la lista se vuelva a desincronizar está en
 * JournalEntryTypesTest. Esta es la otra mitad: que el camino completo funcione.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CreditNotePostTest extends TestCase
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

    /** El caso que fallaba en producción. */
    public function test_una_nota_credito_se_contabiliza(): void
    {
        $nota = $this->notaCreditoBorrador();

        $contabilizada = app(CreditDebitNoteEngine::class)->post($nota);

        $this->assertSame(CreditDebitNote::STATUS_POSTED, $contabilizada->status);
        $this->assertNotNull($contabilizada->journal_entry_id,
            'Sin asiento, la nota no movió nada en la contabilidad.');

        $asiento = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->find($contabilizada->journal_entry_id);

        $this->assertSame('credit_note', $asiento->type,
            'El tipo del asiento es justo el valor que la base rechazaba.');
    }

    /** Una nota débito toma el otro camino del mismo ternario. */
    public function test_una_nota_debito_se_contabiliza(): void
    {
        $nota = $this->notaCreditoBorrador(tipo: CreditDebitNote::TYPE_DEBIT);

        $contabilizada = app(CreditDebitNoteEngine::class)->post($nota);

        $asiento = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->find($contabilizada->journal_entry_id);

        $this->assertSame('debit_note', $asiento->type);
    }

    /** El asiento de la nota queda cuadrado. */
    public function test_el_asiento_de_la_nota_cuadra(): void
    {
        $nota = app(CreditDebitNoteEngine::class)->post($this->notaCreditoBorrador());

        $asiento = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->with('lines')
            ->find($nota->journal_entry_id);

        $debitos = $asiento->lines->sum(fn ($l) => (float) $l->debit);
        $creditos = $asiento->lines->sum(fn ($l) => (float) $l->credit);

        $this->assertGreaterThan(0, $debitos, 'Un asiento en ceros no reversa nada.');
        $this->assertEqualsWithDelta($debitos, $creditos, 0.01,
            'El asiento de la nota quedó descuadrado.');
    }

    /**
     * Contabilizar es lo que destraba el envío a la DIAN.
     *
     * Este era el segundo síntoma del mismo fallo: el usuario no veía el botón
     * de enviar y parecía una funcionalidad faltante, cuando en realidad la nota
     * nunca lograba salir de borrador.
     */
    public function test_al_contabilizar_se_habilita_el_envio_a_la_dian(): void
    {
        $nota = $this->notaCreditoBorrador();

        $this->assertFalse($nota->canResendToDian(),
            'En borrador todavía no hay nada que transmitir.');

        $contabilizada = app(CreditDebitNoteEngine::class)->post($nota);

        $this->assertTrue($contabilizada->canResendToDian(),
            'Contabilizada, el botón de enviar a la DIAN tiene que aparecer.');
    }

    // --------------------------------------------------------- auxiliares

    private function notaCreditoBorrador(string $tipo = CreditDebitNote::TYPE_CREDIT): CreditDebitNote
    {
        $factura = $this->facturaContabilizada();

        $nota = CreditDebitNote::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'sale_invoice_id' => $factura->id,
            'type' => $tipo,
            'prefix' => 'ZZNC',
            'number' => random_int(100000, 999999),
            'date' => now()->toDateString(),
            'reason_code' => 2,
            'reason_description' => 'Prueba automatizada',
            'affects_inventory' => false,
            'currency' => 'COP',
            'status' => CreditDebitNote::STATUS_DRAFT,
            'subtotal' => 200000,
            'total' => 200000,
        ]);

        CreditDebitNoteLine::withoutGlobalScopes()->create([
            'credit_debit_note_id' => $nota->id,
            'line_number' => 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => 2,
            'unit_price' => 100000,
            'subtotal' => 200000,
            'total' => 200000,
        ]);

        $this->limpiar[] = function () use ($nota) {
            $asientos = JournalEntry::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->company->id)
                ->where('reference', 'like', '%ZZNC%')
                ->pluck('id');
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
            DB::table('credit_debit_note_lines')->where('credit_debit_note_id', $nota->id)->delete();
            DB::table('credit_debit_notes')->where('id', $nota->id)->delete();
        };

        return $nota->fresh(['lines']);
    }

    private function facturaContabilizada(): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZNCF',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'pos',
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => SaleInvoice::STATUS_DRAFT,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            'subtotal' => 200000,
            'total' => 200000,
            'net_payable' => 200000,
        ]);

        SaleInvoiceLine::withoutGlobalScopes()->create([
            'sale_invoice_id' => $factura->id,
            'line_number' => 1,
            'product_id' => $this->producto->id,
            'description' => $this->producto->name,
            'quantity' => 2,
            'unit_price' => 100000,
            'cost_at_sale' => 50000,
            'subtotal' => 200000,
            'total' => 200000,
        ]);

        $this->limpiar[] = function () use ($factura) {
            $asientos = JournalEntry::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->company->id)
                ->where('reference', 'like', '%ZZNCF%')
                ->pluck('id');
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
            DB::table('inventory_movements')->where('reference_id', $factura->id)->delete();
            DB::table('sale_invoice_lines')->where('sale_invoice_id', $factura->id)->delete();
            DB::table('sale_invoices')->where('id', $factura->id)->delete();
        };

        return app(SaleInvoiceEngine::class)->post($factura->fresh(['lines']), allowNegativeStock: true);
    }

    private function prepararCatalogo(): void
    {
        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ CLIENTE NOTA',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZNC'.random_int(10000, 99999),
            'name' => 'ZZ Producto nota',
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
