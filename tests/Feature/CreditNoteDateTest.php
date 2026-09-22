<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\Dian\Resolution;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Sales\CreditDebitNoteEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * La fecha de la nota tiene que ser la del día en que se firma.
 *
 * Es la regla CAD09 de la DIAN, y la firma la pone el proveedor en el momento
 * del envío. Una nota contabilizada días atrás se rechaza siempre —con
 * cualquier consecutivo—, y el rechazo llegaba envuelto en un código 99 que el
 * sistema traducía a «Documento ya emitido». Un cliente perdió dos días
 * persiguiendo un consecutivo que nunca fue el problema.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CreditNoteDateTest extends TestCase
{
    private Company $company;

    private Location $sede;

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

        $this->cliente = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ CLIENTE FECHA',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
            ->whereKey($this->cliente->id)->forceDelete();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** La nota vieja pasa a hoy, y su asiento con ella. */
    public function test_la_nota_y_su_asiento_pasan_a_la_fecha_de_hoy(): void
    {
        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador(hace: 11));

        $this->assertFalse($nota->date->isToday());

        $fechada = app(CreditDebitNoteEngine::class)->ponerFechaDeHoy($nota);

        $this->assertTrue($fechada->date->isToday());

        $asiento = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->find($fechada->journal_entry_id);

        $this->assertTrue($asiento->date->isToday(),
            'El libro quedaría hablando de un documento emitido otro día.');
    }

    /** Una nota ya autorizada no se toca. */
    public function test_una_nota_con_cufe_no_cambia_de_fecha(): void
    {
        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador(hace: 11));
        $nota->update(['cufe' => str_repeat('b', 96)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya autorizó/');

        app(CreditDebitNoteEngine::class)->ponerFechaDeHoy($nota->fresh());
    }

    /** Y la que ya está fechada hoy lo dice, en vez de tocar el asiento por nada. */
    public function test_una_nota_de_hoy_no_se_vuelve_a_fechar(): void
    {
        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador(hace: 0));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya está fechada hoy/');

        app(CreditDebitNoteEngine::class)->ponerFechaDeHoy($nota);
    }

    // --------------------------------------------------------- auxiliares

    private function facturaReferenciada(): int
    {
        $id = DB::table('sale_invoices')->insertGetId([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZFECF',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'electronic',
            'date' => now()->subDays(20)->toDateString(),
            'currency' => 'COP',
            'status' => 'posted',
            'payment_status' => 'pendiente',
            'subtotal' => 100000,
            'total' => 100000,
            'net_payable' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $id)->delete();

        return $id;
    }

    private function notaBorrador(int $hace): CreditDebitNote
    {
        $nota = CreditDebitNote::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'sale_invoice_id' => $this->facturaReferenciada(),
            'type' => CreditDebitNote::TYPE_CREDIT,
            'prefix' => 'ZZFEC',
            'number' => random_int(1, 99999),
            'date' => now()->subDays($hace)->toDateString(),
            'reason_code' => 2,
            'status' => CreditDebitNote::STATUS_DRAFT,
            'currency' => 'COP',
            'created_by_user_id' => auth()->id(),
        ]);

        $nota->lines()->create([
            'line_number' => 1,
            'description' => 'ZZ LINEA FECHA',
            'quantity' => 1,
            'unit_price' => 100000,
            'subtotal' => 100000,
            'total' => 100000,
        ]);

        $this->limpiar[] = function () use ($nota) {
            $entryIds = CreditDebitNote::withoutGlobalScopes()->whereKey($nota->id)->pluck('journal_entry_id');
            DB::table('credit_debit_note_lines')->where('credit_debit_note_id', $nota->id)->delete();
            DB::table('credit_debit_notes')->where('id', $nota->id)->delete();
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $entryIds)->delete();
            DB::table('journal_entries')->whereIn('id', $entryIds)->delete();
        };

        return $nota->fresh(['lines']);
    }
}
