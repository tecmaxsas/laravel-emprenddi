<?php

namespace Tests\Feature;

use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\Company;
use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Sales\DocumentNumberer;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Elegir con qué resolución se numera una factura.
 *
 * La asignación resolución↔sede es una comodidad, no una regla del negocio: una
 * empresa con varias resoluciones vigentes necesita poder emitir con una
 * concreta —la del contrato de un cliente, la que está por vencerse y hay que
 * agotar— sin reasignarla y volver a dejarla como estaba.
 *
 * Lo que se cuida aquí es **que no se repita un consecutivo**. Sin contador
 * propio (solo existe cuando la resolución está asignada a una sede), el número
 * se deduce de la realidad, y estas pruebas comprueban que las dos vías —la de
 * la sede y la directa— se ven la una a la otra.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class InvoiceResolutionChoiceTest extends TestCase
{
    private Company $company;

    private Location $sede;

    private Resolution $asignada;

    private Resolution $suelta;

    private int $terceroId;

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
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->firstOrFail();

        $tercero = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ CLIENTE RESOLUCION',
            'is_customer' => true,
            'active' => true,
        ]);
        $this->terceroId = $tercero->id;
        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()->whereKey($tercero->id)->forceDelete();

        $this->asignada = $this->crearResolucion('ZZA', 1000, 1999, asignar: true);
        $this->suelta = $this->crearResolucion('ZZB', 5000, 5999, asignar: false);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** Lo esencial: se puede numerar con una resolución que nadie asignó. */
    public function test_se_numera_con_una_resolucion_sin_asignar(): void
    {
        $doc = app(DocumentNumberer::class)
            ->reserveForResolution($this->suelta->id, $this->company->id);

        $this->assertSame('ZZB', $doc['prefix']);
        $this->assertSame(5000, $doc['number'], 'Sin facturas previas, arranca en el inicio del rango.');
        $this->assertSame($this->suelta->id, $doc['resolution_id']);
    }

    /** Dos reservas seguidas no devuelven el mismo número. */
    public function test_dos_reservas_seguidas_no_repiten_numero(): void
    {
        $numerador = app(DocumentNumberer::class);

        $primero = $numerador->reserveForResolution($this->suelta->id, $this->company->id)['number'];
        $this->facturaCon('ZZB', $primero);

        $segundo = $numerador->reserveForResolution($this->suelta->id, $this->company->id)['number'];

        $this->assertSame($primero + 1, $segundo);
    }

    /**
     * El caso que puede costar caro: emitir por la vía de la sede y luego por
     * la directa, con la misma resolución. Si cada una llevara su cuenta
     * aparte, la segunda repetiría un número ya usado.
     */
    public function test_las_dos_vias_no_se_pisan(): void
    {
        $numerador = app(DocumentNumberer::class);

        $porSede = $numerador->reserveForLocation($this->sede->id, Resolution::KIND_ELECTRONIC)['number'];
        $this->facturaCon('ZZA', $porSede);

        $directo = $numerador->reserveForResolution($this->asignada->id, $this->company->id)['number'];

        $this->assertGreaterThan($porSede, $directo,
            'La vía directa tiene que ver lo que emitió la de la sede.');

        $this->facturaCon('ZZA', $directo);

        $siguientePorSede = $numerador->reserveForLocation($this->sede->id, Resolution::KIND_ELECTRONIC)['number'];

        $this->assertGreaterThan($directo, $siguientePorSede,
            'Y la de la sede tiene que ver lo que emitió la directa.');
    }

    /** Numerar por la vía directa adelanta también el contador de la sede. */
    public function test_la_via_directa_adelanta_el_contador_de_la_sede(): void
    {
        $antes = LocationResolution::query()
            ->where('dian_resolution_id', $this->asignada->id)
            ->value('current_consecutive');

        $doc = app(DocumentNumberer::class)
            ->reserveForResolution($this->asignada->id, $this->company->id);

        $despues = LocationResolution::query()
            ->where('dian_resolution_id', $this->asignada->id)
            ->value('current_consecutive');

        $this->assertGreaterThan($antes, $despues);
        $this->assertSame($doc['number'] + 1, (int) $despues);
    }

    /**
     * Facturar con una resolución que la sede no tenía crea esa asignación, con
     * el consecutivo al día. Es lo que pidió el negocio: no hay que reasignar
     * nada a mano.
     */
    public function test_facturar_con_una_resolucion_ajena_a_la_sede_crea_su_contador(): void
    {
        $this->assertDatabaseMissing('dian_location_resolutions', [
            'location_id' => $this->sede->id,
            'dian_resolution_id' => $this->suelta->id,
        ]);

        $doc = app(DocumentNumberer::class)
            ->reserveForResolution($this->suelta->id, $this->company->id, $this->sede->id);

        $asignacion = LocationResolution::query()
            ->where('location_id', $this->sede->id)
            ->where('dian_resolution_id', $this->suelta->id)
            ->first();

        $this->assertNotNull($asignacion, 'Debió crearse la asignación para llevar el contador.');
        $this->assertSame($doc['number'] + 1, (int) $asignacion->current_consecutive);
    }

    /**
     * Pero esa asignación nace INACTIVA. Facturar una vez con otra resolución
     * no puede cambiar en silencio con cuál factura esa sede de ahí en
     * adelante: la próxima factura sin elegir resolución debe seguir saliendo
     * de la de siempre.
     */
    public function test_la_asignacion_creada_no_se_vuelve_la_predeterminada(): void
    {
        $numerador = app(DocumentNumberer::class);

        $numerador->reserveForResolution($this->suelta->id, $this->company->id, $this->sede->id);

        $asignacion = LocationResolution::query()
            ->where('location_id', $this->sede->id)
            ->where('dian_resolution_id', $this->suelta->id)
            ->first();

        $this->assertFalse((bool) $asignacion->active);

        $porSede = $numerador->reserveForLocation($this->sede->id, Resolution::KIND_ELECTRONIC);

        $this->assertSame('ZZA', $porSede['prefix'],
            'Sin elegir resolución, la sede sigue facturando con la suya.');
    }

    /** Facturar dos veces con la misma resolución ajena no duplica la asignación. */
    public function test_no_se_duplica_la_asignacion(): void
    {
        $numerador = app(DocumentNumberer::class);

        $numerador->reserveForResolution($this->suelta->id, $this->company->id, $this->sede->id);
        $numerador->reserveForResolution($this->suelta->id, $this->company->id, $this->sede->id);

        $this->assertSame(1, LocationResolution::query()
            ->where('location_id', $this->sede->id)
            ->where('dian_resolution_id', $this->suelta->id)
            ->count());
    }

    /** Una resolución agotada no emite: se corta con el motivo. */
    public function test_una_resolucion_agotada_no_emite(): void
    {
        $agotada = $this->crearResolucion('ZZC', 100, 101, asignar: false);
        $this->facturaCon('ZZC', 101);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/se agotó/');

        app(DocumentNumberer::class)->reserveForResolution($agotada->id, $this->company->id);
    }

    /** Una resolución inactiva tampoco. */
    public function test_una_resolucion_inactiva_no_emite(): void
    {
        $this->suelta->update(['active' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/inactiva/');

        app(DocumentNumberer::class)->reserveForResolution($this->suelta->id, $this->company->id);
    }

    /**
     * El id de la resolución llega de un formulario. Una de otra empresa tiene
     * que rebotar: emitir con el prefijo de otro cliente sería grave.
     */
    public function test_una_resolucion_de_otra_empresa_rebota(): void
    {
        $otra = Company::query()->where('id', '!=', $this->company->id)->first();

        if (! $otra) {
            $this->markTestSkipped('Solo hay una empresa en la base: no hay con qué cruzar.');
        }

        $ajena = Resolution::withoutGlobalScopes()->create([
            'company_id' => $otra->id,
            'kind' => Resolution::KIND_ELECTRONIC,
            'document_type_id' => 1,
            'document_type_name' => 'Factura Electrónica',
            'prefix' => 'ZZX',
            'resolution_number' => 'ZZ-AJENA',
            'range_from' => 1,
            'range_to' => 99,
            'active' => true,
        ]);
        $this->limpiar[] = fn () => DB::table('dian_resolutions')->where('id', $ajena->id)->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no es de tu empresa/');

        app(DocumentNumberer::class)->reserveForResolution($ajena->id, $this->company->id);
    }

    /** El tipo lo decide la resolución, no el selector del formulario. */
    public function test_el_tipo_de_factura_sale_de_la_resolucion(): void
    {
        $pos = $this->crearResolucion('ZZP', 1, 999, asignar: false, kind: Resolution::KIND_POS);

        $doc = app(DocumentNumberer::class)->reserveForResolution($pos->id, $this->company->id);

        $this->assertSame(Resolution::KIND_POS, $doc['kind'],
            'Con una resolución POS la factura es POS, aunque el formulario dijera electrónica.');
    }

    /** El selector lista las sueltas, no solo las asignadas a una sede. */
    public function test_el_selector_incluye_las_no_asignadas(): void
    {
        $opciones = SaleInvoiceResource::resolutionOptions('electronic');

        $this->assertArrayHasKey($this->asignada->id, $opciones);
        $this->assertArrayHasKey($this->suelta->id, $opciones,
            'Una resolución sin asignar es justamente la que este selector viene a permitir.');

        $this->assertStringContainsString('sin asignar', $opciones[$this->suelta->id]);
        $this->assertStringNotContainsString('sin asignar', $opciones[$this->asignada->id]);
    }

    /** Y no mezcla tipos: una POS no aparece entre las electrónicas. */
    public function test_el_selector_no_mezcla_pos_con_electronica(): void
    {
        $pos = $this->crearResolucion('ZZQ', 1, 999, asignar: false, kind: Resolution::KIND_POS);

        $this->assertArrayNotHasKey($pos->id, SaleInvoiceResource::resolutionOptions('electronic'));
        $this->assertArrayHasKey($pos->id, SaleInvoiceResource::resolutionOptions('pos'));
    }

    /** Una vencida se puede elegir, pero la etiqueta lo grita. */
    public function test_una_resolucion_vencida_se_marca_en_el_selector(): void
    {
        $this->suelta->update(['date_to' => now()->subMonth()->toDateString()]);

        $opciones = SaleInvoiceResource::resolutionOptions('electronic');

        $this->assertStringContainsString('VENCIDA', $opciones[$this->suelta->id],
            'Emitir con una resolución vencida es un rechazo seguro de la DIAN: hay que verlo.');
    }

    // --------------------------------------------------------- auxiliares

    private function crearResolucion(
        string $prefijo,
        int $desde,
        int $hasta,
        bool $asignar,
        string $kind = Resolution::KIND_ELECTRONIC,
    ): Resolution {
        $resolucion = Resolution::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'kind' => $kind,
            'document_type_id' => 1,
            'document_type_name' => 'Factura Electrónica',
            'prefix' => $prefijo,
            'resolution_number' => 'ZZ-'.random_int(10000, 99999),
            'range_from' => $desde,
            'range_to' => $hasta,
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($resolucion, $prefijo) {
            // Incluye las asignaciones que la propia facturacion haya creado.

            DB::table('sale_invoices')->where('company_id', $this->company->id)
                ->where('prefix', $prefijo)->delete();
            DB::table('dian_location_resolutions')->where('dian_resolution_id', $resolucion->id)->delete();
            DB::table('dian_resolutions')->where('id', $resolucion->id)->delete();
        };

        if ($asignar) {
            // Se desactivan las asignaciones que ya tuviera la sede para ese
            // tipo: si no, reserveForLocation podría tomar otra y la prueba
            // mediría algo distinto de lo que dice medir.
            $previas = LocationResolution::query()
                ->where('location_id', $this->sede->id)
                ->where('active', true)
                ->pluck('id');

            DB::table('dian_location_resolutions')->whereIn('id', $previas)->update(['active' => false]);
            $this->limpiar[] = fn () => DB::table('dian_location_resolutions')
                ->whereIn('id', $previas)->update(['active' => true]);

            LocationResolution::create([
                'location_id' => $this->sede->id,
                'dian_resolution_id' => $resolucion->id,
                'current_consecutive' => $desde,
                'active' => true,
            ]);
        }

        return $resolucion;
    }

    private function facturaCon(string $prefijo, int $numero): SaleInvoice
    {
        return SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'third_party_id' => $this->terceroId,
            'prefix' => $prefijo,
            'number' => $numero,
            'date' => now()->toDateString(),
            'currency' => 'COP',
            'status' => SaleInvoice::STATUS_DRAFT,
            'payment_status' => SaleInvoice::PAYMENT_PENDIENTE,
        ]);
    }
}
