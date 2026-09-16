<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La bitácora comparando campos que no son texto.
 *
 * `equivalentes()` terminaba en `(string) $a === (string) $b`. Los dos lados no
 * vienen del mismo sitio —el anterior de `getOriginal()`, el nuevo de
 * `getChanges()`— y Eloquent no siempre les aplica el mismo casteo: en un campo
 * casteado a `array`, uno llega como arreglo y el otro como el JSON crudo de la
 * base. Hacer `(string)` sobre un arreglo mata la petición entera.
 *
 * Lo que hizo este fallo tan difícil de encontrar es que la bitácora se engancha
 * a **todo** `update()`. El error no aparecía en la auditoría: aparecía en la
 * operación que se estuviera haciendo. En producción salía al enviar una nota
 * crédito a la DIAN —que guarda la respuesta del proveedor en un campo `array`—
 * y el mensaje era «Array to string conversion», sin ninguna pista de que la
 * auditoría tuviera algo que ver. Se buscó tres veces en el lugar equivocado.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class AuditJsonFieldTest extends TestCase
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
            'name' => 'ZZAUDJSON CLIENTE',
            'is_customer' => true,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()
            ->whereKey($this->cliente->id)->forceDelete();

        DB::table('audit_logs')->where('auditable_label', 'like', '%ZZAUDJSON%')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('audit_logs')->where('auditable_label', 'like', '%ZZAUDJSON%')->delete();

        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /**
     * El caso exacto de producción: guardar la respuesta de la DIAN.
     *
     * El campo `dian_response` está casteado a `array`. Guardarlo reventaba, y
     * el error salía a nombre de quien estuviera guardando.
     */
    public function test_guardar_un_campo_json_no_rompe_la_operacion(): void
    {
        $factura = $this->conRespuestaPrevia();

        // El segundo envío, que es donde reventaba: ahora el valor anterior sí
        // existe y Eloquent lo entrega ya casteado a arreglo, mientras el nuevo
        // llega como el JSON crudo.
        $factura->update([
            'dian_response' => [
                'message' => 'The given data was invalid.',
                'errors' => ['number' => ['number tiene que estar entre 1 y 1000']],
            ],
            'dian_status' => SaleInvoice::DIAN_REJECTED,
        ]);

        $this->assertSame(SaleInvoice::DIAN_REJECTED, $factura->fresh()->dian_status,
            'La operación completa moría por culpa de la bitácora.');
    }

    /**
     * El primer envío nunca falló, y por eso costó tanto reproducirlo.
     *
     * Con `dian_response` en null, el valor anterior es null y `(string) null`
     * no se queja. El fallo solo aparece al **reenviar**, cuando ya hay una
     * respuesta guardada — que es exactamente lo que pasaba con la NC1.
     */
    public function test_el_primer_envio_nunca_fallaba(): void
    {
        $factura = $this->factura();

        $factura->update(['dian_response' => ['message' => 'Primera']]);

        $this->assertNotNull($factura->fresh()->dian_response);
    }

    /** Y el cambio queda anotado, no simplemente ignorado. */
    public function test_el_cambio_del_campo_json_queda_en_la_bitacora(): void
    {
        $factura = $this->conRespuestaPrevia();

        $factura->update(['dian_response' => ['message' => 'Otra respuesta distinta']]);

        $entrada = AuditLog::query()
            ->where('auditable_type', SaleInvoice::class)
            ->where('auditable_id', $factura->id)
            ->where('event', AuditLog::EVENT_UPDATED)
            ->latest('id')
            ->first();

        $this->assertNotNull($entrada, 'El cambio tenía que quedar anotado.');
        $this->assertArrayHasKey('dian_response', $entrada->changes ?? [],
            'Se registró el evento pero sin decir qué cambió.');
    }

    /** Guardar el mismo JSON dos veces no inventa un cambio. */
    public function test_el_mismo_json_dos_veces_no_es_un_cambio(): void
    {
        $factura = $this->factura();

        $respuesta = ['message' => 'Igual', 'errors' => ['a' => ['b']]];

        $factura->update(['dian_response' => $respuesta]);
        $factura = $factura->fresh();

        $antes = AuditLog::query()
            ->where('auditable_type', SaleInvoice::class)
            ->where('auditable_id', $factura->id)
            ->count();

        // El mismo contenido: para quien audita, aquí no pasó nada.
        $factura->update(['dian_response' => $respuesta, 'dian_status_code' => '99']);

        $ultima = AuditLog::query()
            ->where('auditable_type', SaleInvoice::class)
            ->where('auditable_id', $factura->id)
            ->latest('id')
            ->first();

        $this->assertGreaterThan($antes - 1, $antes);
        $this->assertArrayNotHasKey('dian_response', $ultima->changes ?? [],
            'El JSON no cambió: anotarlo sería ruido que esconde los cambios de verdad.');
    }

    /** Una fecha se anota como se lee, no con su estructura interna. */
    public function test_una_fecha_se_anota_legible(): void
    {
        $factura = $this->factura();

        $factura->update(['due_date' => now()->addDays(30)->toDateString()]);

        $entrada = AuditLog::query()
            ->where('auditable_type', SaleInvoice::class)
            ->where('auditable_id', $factura->id)
            ->where('event', AuditLog::EVENT_UPDATED)
            ->latest('id')
            ->first();

        $anotado = $entrada?->changes['due_date']['despues'] ?? null;

        $this->assertIsString($anotado,
            'Un objeto de fecha se guardaría como {"date":…,"timezone_type":3,…} y nadie entiende eso.');
        $this->assertStringNotContainsString('timezone_type', $anotado);
    }

    // --------------------------------------------------------- auxiliares

    /**
     * Una factura que ya tiene respuesta guardada.
     *
     * El `fresh()` es lo que importa: obliga a releerla de la base, así el
     * «valor anterior» de la próxima actualización sale casteado a arreglo. Sin
     * eso la prueba pasa aunque el fallo siga ahí.
     */
    private function conRespuestaPrevia(): SaleInvoice
    {
        $factura = $this->factura();

        $factura->update([
            'dian_response' => ['message' => 'Respuesta anterior', 'errors' => ['x' => ['y']]],
        ]);

        return $factura->fresh();
    }

    private function factura(): SaleInvoice
    {
        $factura = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->cliente->id,
            'prefix' => 'ZZAUDJSON',
            'number' => random_int(100000, 999999),
            'invoice_kind' => 'electronic',
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => SaleInvoice::STATUS_DRAFT,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
            'subtotal' => 100000,
            'total' => 100000,
            'net_payable' => 100000,
        ]);

        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $factura->id)->delete();

        return $factura;
    }
}
