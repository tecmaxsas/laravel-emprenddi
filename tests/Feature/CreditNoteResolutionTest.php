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
use App\Services\Sales\DocumentNumberer;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * La resolución de notas crédito es de la empresa, no de una sede.
 *
 * La DIAN autoriza los rangos de **facturación** por establecimiento: cada punto
 * de venta factura con el suyo, y por eso esas resoluciones se asignan a una
 * sede. Las notas crédito y débito no funcionan así — son un solo consecutivo
 * para toda la empresa, lo emita quien lo emita.
 *
 * El motor las buscaba por la sede. Como nadie asigna una resolución de notas a
 * una sede, nunca la encontraba, salía en silencio y la nota se quedaba con su
 * número manual («NC1») y sin resolución. El daño aparecía al enviarla, con un
 * mensaje del proveedor que no se podía relacionar con la causa:
 *
 *   «La resolución no está configurada · number tiene que estar entre - »
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CreditNoteResolutionTest extends TestCase
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
            'name' => 'ZZ CLIENTE RESOLUCION',
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

    /** La encuentra sin que nadie la haya asignado a una sede. */
    public function test_la_resolucion_de_notas_se_encuentra_sin_asignarla_a_una_sede(): void
    {
        $resolucion = $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 990000001, hasta: 990001000);

        $this->assertSame(0, DB::table('dian_location_resolutions')
            ->where('dian_resolution_id', $resolucion->id)->count(),
            'La prueba solo vale si de verdad no hay ninguna asignación.');

        $encontrada = app(DocumentNumberer::class)
            ->resolucionGlobalDe($this->company->id, 4, 'credit_debit_notes');

        $this->assertNotNull($encontrada, 'Sin asignación a sede, igual tiene que encontrarla.');
        $this->assertSame($resolucion->id, $encontrada->id);
    }

    /** Y numera desde el rango autorizado, no desde 1. */
    public function test_la_nota_toma_el_consecutivo_de_la_resolucion(): void
    {
        $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 990000001, hasta: 990001000);

        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());

        $this->assertSame('ZZNC', $nota->prefix);
        $this->assertSame(990000001, (int) $nota->number,
            'Debe arrancar en el inicio del rango autorizado, no en 1.');
        $this->assertNotNull($nota->dian_resolution_id);
    }

    /** Dos notas seguidas no repiten número. */
    public function test_dos_notas_no_repiten_consecutivo(): void
    {
        $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 990000001, hasta: 990001000);

        $primera = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());
        $segunda = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());

        $this->assertSame(990000001, (int) $primera->number);
        $this->assertSame(990000002, (int) $segunda->number,
            'El consecutivo sale de las notas ya emitidas, no de un contador por sede.');
    }

    /** La nota débito usa su propia resolución, no la de crédito. */
    public function test_la_nota_debito_no_toma_la_resolucion_de_credito(): void
    {
        $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 990000001, hasta: 990001000);
        $this->resolucion(documentTypeId: 5, prefijo: 'ZZND', desde: 880000001, hasta: 880001000);

        $debito = app(CreditDebitNoteEngine::class)
            ->post($this->notaBorrador(tipo: CreditDebitNote::TYPE_DEBIT));

        $this->assertSame('ZZND', $debito->prefix);
        $this->assertSame(880000001, (int) $debito->number);
    }

    /**
     * Sin resolución la nota sigue saliendo, con su número manual.
     *
     * Bloquearla sería peor: hay empresas que llevan notas para control interno
     * sin transmitirlas. Lo que no puede pasar es que el usuario se entere solo
     * cuando la DIAN la rechaza — de eso se encarga el aviso en pantalla.
     */
    public function test_sin_resolucion_la_nota_se_contabiliza_igual(): void
    {
        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());

        $this->assertSame(CreditDebitNote::STATUS_POSTED, $nota->status);
        $this->assertNull($nota->dian_resolution_id);
    }

    // ----------------------------------------------------- el rescate

    /** Una nota vieja sin resolución se puede renumerar. */
    public function test_una_nota_sin_resolucion_se_puede_renumerar(): void
    {
        // Contabilizada antes de que existiera la resolución: queda en NC1.
        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());
        $numeroViejo = $nota->fullNumber();
        $this->assertNull($nota->dian_resolution_id);

        $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 990000001, hasta: 990001000);

        $rescatada = app(CreditDebitNoteEngine::class)->asignarResolucionDian($nota);

        $this->assertSame('ZZNC', $rescatada->prefix);
        $this->assertSame(990000001, (int) $rescatada->number);
        $this->assertNotNull($rescatada->dian_resolution_id);
        $this->assertNotSame($numeroViejo, $rescatada->fullNumber());
    }

    /**
     * Al renumerar, la nota no se cuenta a sí misma.
     *
     * El siguiente número libre se deduce del más alto ya emitido con ese
     * prefijo. Si la propia nota entra en ese conteo, su número hace que el
     * «siguiente» sea uno más arriba: una NC1 se convertía en NC2 solo por
     * rescatarla, y el usuario perdía el consecutivo que le correspondía.
     */
    public function test_al_renumerar_la_nota_no_se_cuenta_a_si_misma(): void
    {
        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());

        // Numerada a mano con el 1, que es justo el inicio del rango.
        $nota->update(['prefix' => 'ZZNC', 'number' => 1]);

        $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 1, hasta: 1000);

        $rescatada = app(CreditDebitNoteEngine::class)->asignarResolucionDian($nota->fresh());

        $this->assertSame(1, (int) $rescatada->number,
            'Se contó a sí misma y se saltó al 2, perdiendo el consecutivo que le tocaba.');
    }

    /** Pero sí respeta las notas ajenas ya emitidas. */
    public function test_al_renumerar_si_respeta_las_otras_notas(): void
    {
        $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 1, hasta: 1000);

        // Una nota anterior ya ocupa el 1.
        app(CreditDebitNoteEngine::class)->post($this->notaBorrador());

        $segunda = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());
        $segunda->update(['dian_resolution_id' => null, 'prefix' => 'NC', 'number' => 99]);

        $rescatada = app(CreditDebitNoteEngine::class)->asignarResolucionDian($segunda->fresh());

        $this->assertSame(2, (int) $rescatada->number,
            'El 1 ya estaba ocupado por otra nota: le toca el 2.');
    }

    /** Y el asiento se va con ella: su referencia es el número que cambió. */
    public function test_el_asiento_sigue_a_la_nota_renumerada(): void
    {
        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());
        $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 990000001, hasta: 990001000);

        $rescatada = app(CreditDebitNoteEngine::class)->asignarResolucionDian($nota);

        $asiento = JournalEntry::withoutGlobalScope(CompanyScope::class)
            ->find($rescatada->journal_entry_id);

        $this->assertSame($rescatada->fullNumber(), $asiento->reference,
            'El libro quedaría apuntando a un documento que ya no existe.');
    }

    /** Sin resolución cargada, el rescate explica qué hacer. */
    public function test_sin_resolucion_el_rescate_dice_que_hacer(): void
    {
        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());

        try {
            app(CreditDebitNoteEngine::class)->asignarResolucionDian($nota);
            $this->fail('Debió negarse: no hay resolución que asignar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Configuración → DIAN', $e->getMessage());
            $this->assertStringContainsString('no hay que asignarla', mb_strtolower($e->getMessage()),
                'El mensaje tiene que desmentir lo que la gente asume: que va a una sede.');
        }
    }

    /** Una nota que la DIAN ya aceptó no se renumera nunca. */
    public function test_una_nota_aceptada_por_la_dian_no_se_renumera(): void
    {
        $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 990000001, hasta: 990001000);

        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());
        $nota->update([
            'dian_status' => CreditDebitNote::DIAN_ACCEPTED,
            'dian_resolution_id' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya aceptó/');

        app(CreditDebitNoteEngine::class)->asignarResolucionDian($nota->fresh());
    }

    /**
     * Una nota rechazada sí se renumera, aunque ya tenga resolución.
     *
     * «Documento ya emitido» significa que ese consecutivo está ocupado en la
     * DIAN —lo gastó otro sistema, o un envío anterior—: la nota no va a pasar
     * nunca con el número que tiene. Negarse a renumerarla obligaba a anularla
     * y rehacerla, y una nota crédito anulada deja la cuenta del cliente donde
     * no debe.
     */
    public function test_una_nota_rechazada_se_renumera_aunque_tenga_resolucion(): void
    {
        $resolucion = $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 1, hasta: 1000);

        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());
        $this->assertSame(1, (int) $nota->number);
        $this->assertNotNull($nota->dian_resolution_id);

        $nota->update([
            'dian_status' => CreditDebitNote::DIAN_REJECTED,
            'dian_error_message' => 'Documento ya emitido.',
        ]);

        // El consecutivo desde el que debe seguir, porque en la DIAN los
        // anteriores ya están ocupados.
        DB::table('dian_location_resolutions')->insert([
            'location_id' => $this->sede->id,
            'dian_resolution_id' => $resolucion->id,
            'current_consecutive' => 6,
            'active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $renumerada = app(CreditDebitNoteEngine::class)->asignarResolucionDian($nota->fresh());

        $this->assertSame(6, (int) $renumerada->number);
        $this->assertSame(CreditDebitNote::DIAN_PENDING, $renumerada->dian_status,
            'Queda lista para reintentar el envío.');
        $this->assertNull($renumerada->dian_error_message,
            'El motivo del rechazo era del número viejo.');
    }

    /**
     * Y se puede renumerar más de una vez, mientras no se haya enviado.
     *
     * Al renumerar, la nota queda pendiente de envío. Si eso cerrara la puerta,
     * la trampa sería completa: una primera pasada hecha antes de mover el
     * consecutivo devuelve el mismo número —el siguiente libre era ese—, y la
     * segunda, la que de verdad lo arregla, ya no se podría hacer.
     */
    public function test_una_nota_pendiente_de_envio_se_puede_renumerar_otra_vez(): void
    {
        $resolucion = $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 1, hasta: 1000);

        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());
        $nota->update(['dian_status' => CreditDebitNote::DIAN_REJECTED]);

        // Primer intento, sin mover el consecutivo: sale el mismo número.
        $primera = app(CreditDebitNoteEngine::class)->asignarResolucionDian($nota->fresh());
        $this->assertSame(1, (int) $primera->number);
        $this->assertSame(CreditDebitNote::DIAN_PENDING, $primera->dian_status);

        DB::table('dian_location_resolutions')->insert([
            'location_id' => $this->sede->id,
            'dian_resolution_id' => $resolucion->id,
            'current_consecutive' => 6,
            'active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $segunda = app(CreditDebitNoteEngine::class)->asignarResolucionDian($primera->fresh());

        $this->assertSame(6, (int) $segunda->number,
            'Sin esto, quien renumeró antes de mover el contador se queda sin salida.');
    }

    /**
     * Lo que sí espera: una nota cuyo número ya viajó a la DIAN.
     *
     * Hasta que llegue la respuesta nadie sabe con qué se quedó allá, y cambiarlo
     * mientras tanto deja ese número en el aire.
     */
    public function test_una_nota_enviada_no_se_renumera_hasta_que_la_dian_responda(): void
    {
        $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 1, hasta: 1000);

        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());
        $nota->update(['dian_status' => CreditDebitNote::DIAN_SENT]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/esperando la respuesta/');

        app(CreditDebitNoteEngine::class)->asignarResolucionDian($nota->fresh());
    }

    /**
     * Pero una nota con CUFE no se renumera, diga lo que diga su estado.
     *
     * El CUFE es la prueba de que la DIAN le dio validez en algún momento. Si
     * alguien dejó el estado mal guardado después —un reintento, una
     * sincronización a medias—, el estado miente y el CUFE no.
     */
    public function test_una_nota_con_cufe_no_se_renumera(): void
    {
        $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 1, hasta: 1000);

        $nota = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());
        $nota->update([
            'dian_status' => CreditDebitNote::DIAN_REJECTED,
            'cufe' => str_repeat('a', 96),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CUFE/');

        app(CreditDebitNoteEngine::class)->asignarResolucionDian($nota->fresh());
    }

    // ------------------------------------------- saltar hacia adelante

    /**
     * Subir el contador adelanta el consecutivo, sin repetir lo ya emitido.
     *
     * Hace falta cuando la DIAN ya tiene notas emitidas fuera de Emprenddi —un
     * sistema anterior, una migración a medias— y la siguiente no puede salir
     * con un número que allá ya existe. Como la numeración de notas no se
     * teclea en ninguna pantalla, el único contador que hay vive en las
     * asignaciones de la resolución: es el que mueve
     * `scripts/consecutivo-notas.php`, y esta prueba fija que moverlo sirva
     * para lo que se cree que sirve.
     */
    public function test_el_contador_de_asignaciones_adelanta_el_consecutivo(): void
    {
        $resolucion = $this->resolucion(documentTypeId: 4, prefijo: 'ZZNC', desde: 1, hasta: 1000);

        $primera = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());
        $this->assertSame(1, (int) $primera->number);

        // La fila va inactiva a propósito: lleva la cuenta sin convertirse en
        // la resolución predeterminada de esa sede.
        DB::table('dian_location_resolutions')->insert([
            'location_id' => $this->sede->id,
            'dian_resolution_id' => $resolucion->id,
            'current_consecutive' => 6,
            'active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $siguiente = app(CreditDebitNoteEngine::class)->post($this->notaBorrador());

        $this->assertSame(6, (int) $siguiente->number,
            'El contador manda sobre «la última emitida más uno»: si no, saldría la 2.');

        $this->assertSame(7, (int) DB::table('dian_location_resolutions')
            ->where('dian_resolution_id', $resolucion->id)
            ->value('current_consecutive'),
            'Tras emitir, el contador queda listo para la siguiente.');
    }

    // --------------------------------------------------------- auxiliares

    private function resolucion(int $documentTypeId, string $prefijo, int $desde, int $hasta): Resolution
    {
        $resolucion = Resolution::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'kind' => Resolution::KIND_ELECTRONIC,
            'document_type_id' => $documentTypeId,
            'document_type_name' => Resolution::DOCUMENT_TYPES[$documentTypeId],
            'prefix' => $prefijo,
            'resolution_number' => '18760000'.random_int(100, 999),
            'range_from' => $desde,
            'range_to' => $hasta,
            'date_from' => now()->subMonth()->toDateString(),
            'date_to' => now()->addYear()->toDateString(),
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($resolucion) {
            DB::table('dian_location_resolutions')->where('dian_resolution_id', $resolucion->id)->delete();
            DB::table('dian_resolutions')->where('id', $resolucion->id)->delete();
        };

        return $resolucion;
    }

    /**
     * La factura que la nota referencia.
     *
     * Solo se necesita que exista: el asiento de la nota recorre las líneas de
     * la nota, no las de la factura, así que no hace falta contabilizarla ni
     * moverle inventario.
     */
    private function facturaReferenciada(): int
    {
        $id = DB::table('sale_invoices')->insertGetId([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZRESF',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'electronic',
            'date' => now()->toDateString(),
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

    private function notaBorrador(string $tipo = CreditDebitNote::TYPE_CREDIT): CreditDebitNote
    {
        $nota = CreditDebitNote::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'sale_invoice_id' => $this->facturaReferenciada(),
            'type' => $tipo,
            'prefix' => $tipo === CreditDebitNote::TYPE_CREDIT ? 'NC' : 'ND',
            'number' => random_int(1, 99),
            'date' => now()->toDateString(),
            'reason_code' => 2,
            'reason_description' => 'Prueba automatizada',
            'affects_inventory' => false,
            'currency' => 'COP',
            'status' => CreditDebitNote::STATUS_DRAFT,
            'subtotal' => 100000,
            'total' => 100000,
        ]);

        DB::table('credit_debit_note_lines')->insert([
            'credit_debit_note_id' => $nota->id,
            'line_number' => 1,
            'description' => 'Servicio devuelto',
            'quantity' => 1,
            'unit_price' => 100000,
            'subtotal' => 100000,
            'total' => 100000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->limpiar[] = function () use ($nota) {
            $asientos = JournalEntry::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->company->id)
                ->where(fn ($q) => $q->where('reference', 'like', '%ZZNC%')
                    ->orWhere('reference', 'like', '%ZZND%')
                    ->orWhere('description', 'like', '%ZZ CLIENTE RESOLUCION%'))
                ->pluck('id');
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
            DB::table('credit_debit_note_lines')->where('credit_debit_note_id', $nota->id)->delete();
            DB::table('credit_debit_notes')->where('id', $nota->id)->delete();
        };

        return $nota->fresh(['lines']);
    }
}
