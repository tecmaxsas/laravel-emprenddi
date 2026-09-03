<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dian\CompanyConfig;
use App\Models\Dian\Municipality;
use App\Models\Dian\PayrollTestDocument;
use App\Models\User;
use App\Services\Dian\PayrollTestSetBuilder;
use App\Services\Dian\PayrollTestSetRunner;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Set de pruebas de la habilitación de nómina electrónica.
 *
 * La DIAN pide 10 nóminas y 10 notas de ajuste. Lo que se prueba aquí es que
 * el proceso se pueda retomar: la validación es asíncrona, así que un envío
 * falla, se reintenta más tarde, y lo que ya pasó no se vuelve a mandar.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PayrollHabilitationTest extends TestCase
{
    private Company $company;

    private int $companyId;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->firstOrFail();
        $this->companyId = (int) $user->company_id;
        $this->company = Company::findOrFail($this->companyId);
        $this->actingAs($user);

        $this->prepararEmpresa();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    public function test_la_primera_tanda_manda_solo_nominas(): void
    {
        Http::fake(['*' => $this->respuestaRecibida()]);

        $resultado = $this->runner()->run($this->company, $this->config());

        $this->assertSame(PayrollTestSetRunner::DOCUMENTOS_POR_TANDA, $resultado['enviados']);
        $this->assertSame(0, $resultado['errores']);

        // Ninguna nota: cada una necesita el CUNE de su nómina, y en esta
        // pasada las nóminas apenas se están enviando.
        $this->assertSame(
            PayrollTestSetRunner::DOCUMENTOS_POR_TANDA,
            PayrollTestDocument::query()
                ->where('company_id', $this->companyId)
                ->where('kind', PayrollTestDocument::KIND_NOMINA)
                ->where('status', PayrollTestDocument::ENVIADO)
                ->count(),
        );
        $this->assertSame(0, PayrollTestDocument::query()
            ->where('company_id', $this->companyId)
            ->where('kind', PayrollTestDocument::KIND_NOTA)->count());
    }

    /** Volver a lanzarlo no reenvía lo que ya pasó: continúa donde quedó. */
    public function test_la_segunda_tanda_continua_donde_quedo(): void
    {
        Http::fake(['*' => $this->respuestaRecibida()]);

        $this->runner()->run($this->company, $this->config());
        $this->runner()->run($this->company, $this->config());

        $slots = PayrollTestDocument::query()
            ->where('company_id', $this->companyId)
            ->where('kind', PayrollTestDocument::KIND_NOMINA)
            ->pluck('slot')->sort()->values()->all();

        $this->assertSame(range(1, 10), $slots, 'Las dos tandas cubren las 10 nóminas sin repetir.');
    }

    /**
     * La nota sólo sale cuando su nómina ya tiene CUNE. Sin eso la DIAN
     * responde NIAE191a "Documento a Reemplazar no encuentra recibido".
     */
    public function test_la_nota_espera_a_que_su_nomina_tenga_cune(): void
    {
        // Nómina enviada pero sin CUNE: la DIAN todavía no la registró.
        PayrollTestDocument::query()->create([
            'company_id' => $this->companyId,
            'kind' => PayrollTestDocument::KIND_NOMINA,
            'slot' => 1,
            'status' => PayrollTestDocument::ENVIADO,
            'cune' => null,
        ]);

        Http::fake(['*' => $this->respuestaRecibida()]);

        $this->runner()->run($this->company, $this->config());

        $this->assertSame(0, PayrollTestDocument::query()
            ->where('company_id', $this->companyId)
            ->where('kind', PayrollTestDocument::KIND_NOTA)
            ->where('slot', 1)->count(),
            'Se mandó la nota de una nómina que la DIAN aún no ha registrado.');
    }

    public function test_la_nota_apunta_a_su_nomina_con_el_bloque_predecessor(): void
    {
        $nomina = PayrollTestDocument::query()->create([
            'company_id' => $this->companyId,
            'kind' => PayrollTestDocument::KIND_NOMINA,
            'slot' => 1,
            'prefix' => 'NI',
            'consecutive' => 40,
            'cune' => 'cune-de-la-nomina',
            'issue_date' => '2026-08-31',
            'status' => PayrollTestDocument::ENVIADO,
        ]);

        $payload = app(PayrollTestSetBuilder::class)
            ->nota($this->company, $this->config(), $nomina, 'NA', 7);

        $this->assertSame(10, $payload['type_document_id']);
        $this->assertSame(1, $payload['type_note'], 'Tipo 1 = reemplazar.');
        $this->assertSame('NI40', $payload['predecessor']['predecessor_number']);
        $this->assertSame('cune-de-la-nomina', $payload['predecessor']['predecessor_cune']);
        $this->assertSame('2026-08-31', $payload['predecessor']['predecessor_issue_date']);
        $this->assertSame('NA', $payload['prefix']);
        $this->assertSame(7, $payload['consecutive']);
    }

    /**
     * La DIAN responde SOAP y apidian lo pasa a JSON, así que un elemento
     * vacío no llega como null sino como su representación XML:
     * ErrorMessageList: {_attributes: {nil: "true"}}.
     *
     * Leer eso buscando textos devuelve "true", que no es ningún error. Con
     * eso marcábamos como fallidos documentos que la DIAN había aceptado y el
     * usuario veía "Nómina 1 → true" sin nada que corregir.
     */
    public function test_un_acuse_sin_errores_no_se_lee_como_fallo(): void
    {
        Http::fake([
            '*' => Http::response([
                'message' => 'Nomina Individual #NI40 generada con éxito',
                'cune' => 'cune-real',
                'ResponseDian' => ['Envelope' => ['Body' => ['SendTestSetAsyncResponse' => [
                    'SendTestSetAsyncResult' => [
                        'ErrorMessageList' => ['_attributes' => ['nil' => 'true']],
                        'ZipKey' => 'zip-real',
                    ],
                ]]]],
            ], 200),
        ]);

        $resultado = $this->runner()->run($this->company, $this->config());

        $this->assertSame(PayrollTestSetRunner::DOCUMENTOS_POR_TANDA, $resultado['enviados']);
        $this->assertSame(0, $resultado['errores'], 'El "nil: true" del XML se leyó como motivo de rechazo.');

        $documento = PayrollTestDocument::query()
            ->where('company_id', $this->companyId)->where('slot', 1)->first();

        $this->assertSame(PayrollTestDocument::ENVIADO, $documento->status);
        $this->assertNull($documento->error_message);
        $this->assertSame('cune-real', $documento->cune);
        $this->assertTrue($documento->puedeSerReemplazada(), 'Sin esto su nota de ajuste nunca sale.');
    }

    /**
     * Los documentos que quedaron marcados con error por la mala lectura no se
     * reenvían: se relee la respuesta guardada. Reenviarlos sería mandar a la
     * DIAN documentos que ya aceptó.
     */
    public function test_recupera_los_documentos_marcados_con_error_por_mala_lectura(): void
    {
        PayrollTestDocument::query()->create([
            'company_id' => $this->companyId,
            'kind' => PayrollTestDocument::KIND_NOMINA,
            'slot' => 1,
            'prefix' => 'NI',
            'consecutive' => 11,
            'cune' => 'cune-que-si-llego',
            'status' => PayrollTestDocument::ERROR,
            'error_message' => 'true',
            'response' => ['ResponseDian' => ['Envelope' => ['Body' => ['SendTestSetAsyncResponse' => [
                'SendTestSetAsyncResult' => [
                    'ErrorMessageList' => ['_attributes' => ['nil' => 'true']],
                    'ZipKey' => 'zip',
                ],
            ]]]]],
        ]);

        // Un rechazo de verdad no se toca.
        PayrollTestDocument::query()->create([
            'company_id' => $this->companyId,
            'kind' => PayrollTestDocument::KIND_NOMINA,
            'slot' => 2,
            'cune' => 'cune-rechazado',
            'status' => PayrollTestDocument::ERROR,
            'error_message' => 'NIE023',
            'response' => ['ResponseDian' => ['Envelope' => ['Body' => ['SendTestSetAsyncResponse' => [
                'SendTestSetAsyncResult' => ['ErrorMessageList' => ['string' => ['Regla: NIE023, Rechazo: ...']]],
            ]]]]],
        ]);

        Http::fake(['*' => $this->respuestaRecibida()]);

        $this->runner()->run($this->company, $this->config());

        $uno = PayrollTestDocument::query()
            ->where('company_id', $this->companyId)
            ->where('kind', PayrollTestDocument::KIND_NOMINA)->where('slot', 1)->first();

        $this->assertSame(PayrollTestDocument::ENVIADO, $uno->status);
        $this->assertSame(11, $uno->consecutive, 'Se reenvió un documento que la DIAN ya había aceptado.');

        $dos = PayrollTestDocument::query()
            ->where('company_id', $this->companyId)
            ->where('kind', PayrollTestDocument::KIND_NOMINA)->where('slot', 2)->first();

        $this->assertNotSame('cune-rechazado', $dos->cune, 'Un rechazo real sí se reintenta.');
    }

    /** Un error queda anotado con su motivo y se reintenta en la próxima pasada. */
    public function test_un_error_queda_registrado_y_se_reintenta(): void
    {
        Http::fake([
            '*' => Http::response([
                'ResponseDian' => ['Envelope' => ['Body' => ['SendTestSetAsyncResponse' => [
                    'SendTestSetAsyncResult' => ['ErrorMessageList' => ['string' => [
                        'Regla: NIE023, Rechazo: Se debe colocar el Codigo correspondiente',
                    ]]],
                ]]]],
            ], 200),
        ]);

        $resultado = $this->runner()->run($this->company, $this->config());

        $this->assertSame(0, $resultado['enviados']);
        $this->assertGreaterThan(0, $resultado['errores']);

        $documento = PayrollTestDocument::query()
            ->where('company_id', $this->companyId)->where('slot', 1)->first();

        $this->assertSame(PayrollTestDocument::ERROR, $documento->status);
        $this->assertStringContainsString('NIE023', $documento->error_message);

        // Sigue pendiente: la próxima pasada lo vuelve a intentar.
        $this->assertContains(
            ['kind' => PayrollTestDocument::KIND_NOMINA, 'slot' => 1],
            $this->runner()->pendientes($this->company),
        );
    }

    /**
     * El consecutivo no se reusa entre intentos: la DIAN no admite dos
     * documentos con el mismo número aunque el primero fuera rechazado.
     */
    public function test_el_consecutivo_avanza_con_cada_envio(): void
    {
        Http::fake(['*' => $this->respuestaRecibida()]);

        $this->runner()->run($this->company, $this->config());

        $consecutivos = PayrollTestDocument::query()
            ->where('company_id', $this->companyId)
            ->pluck('consecutive')->all();

        $this->assertCount(
            count($consecutivos),
            array_unique($consecutivos),
            'Dos documentos salieron con el mismo consecutivo.',
        );
    }

    /** El set exige ambiente de habilitación, o la DIAN rechaza todo (NIE023). */
    public function test_pone_la_nomina_en_habilitacion_antes_de_empezar(): void
    {
        $this->config()->update([
            'environment' => CompanyConfig::ENV_PRODUCTION,
            'payroll_environment' => CompanyConfig::ENV_PRODUCTION,
        ]);

        Http::fake(['*' => $this->respuestaRecibida()]);

        $this->runner()->run($this->company, $this->config()->refresh());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/config/environment')
            && $request['payroll_type_environment_id'] === CompanyConfig::ENV_TEST
            && $request['type_environment_id'] === CompanyConfig::ENV_PRODUCTION);
    }

    public function test_no_arranca_sin_testsetid(): void
    {
        $this->config()->update(['payroll_test_set_id' => null]);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/TestSetId/');

        $this->runner()->run($this->company, $this->config()->refresh());
    }

    public function test_no_arranca_sin_prefijo_registrado(): void
    {
        $this->company->update(['payroll_prefix' => null]);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/prefijo/');

        $this->runner()->run($this->company->refresh(), $this->config());
    }

    /** Cada documento del set liquida a un trabajador y un periodo distintos. */
    public function test_los_veinte_documentos_del_set_son_distintos(): void
    {
        Http::fake(['*' => $this->respuestaRecibida(conCune: true)]);

        $runner = $this->runner();

        // Suficientes pasadas para cubrir nóminas y notas.
        foreach (range(1, 8) as $ignorado) {
            $runner->run($this->company, $this->config()->refresh());
        }

        $this->assertTrue($runner->completo($this->company), 'El set no se completó en 8 pasadas.');

        $numeros = PayrollTestDocument::query()
            ->where('company_id', $this->companyId)
            ->get()
            ->map(fn ($d) => $d->prefix.$d->consecutive)
            ->all();

        $this->assertCount(20, $numeros);
        $this->assertCount(20, array_unique($numeros), 'Dos documentos del set salieron con el mismo número.');
    }

    private function runner(): PayrollTestSetRunner
    {
        return app(PayrollTestSetRunner::class);
    }

    private function config(): CompanyConfig
    {
        return CompanyConfig::query()->where('company_id', $this->companyId)->firstOrFail();
    }

    private function respuestaRecibida(bool $conCune = true): PromiseInterface
    {
        return Http::response([
            'cune' => $conCune ? 'cune-'.uniqid() : null,
            'ResponseDian' => ['Envelope' => ['Body' => ['SendTestSetAsyncResponse' => [
                'SendTestSetAsyncResult' => ['ZipKey' => 'zip-'.uniqid(), 'ErrorMessageList' => null],
            ]]]],
        ], 200);
    }

    private function prepararEmpresa(): void
    {
        $config = CompanyConfig::query()->firstOrNew(['company_id' => $this->companyId]);
        $original = $config->exists ? $config->replicate() : null;

        $config->fill([
            'api_url' => 'https://apidian.test',
            'api_token' => 'zz-token',
            'company_registered' => true,
            'dian_municipality_id' => $this->municipio(),
            'payroll_software_configured' => true,
            'payroll_test_set_id' => 'zz-set',
            'payroll_test_consecutive' => 1,
            'payroll_environment' => CompanyConfig::ENV_TEST,
        ])->save();

        $prefijosOriginales = $this->company->only(['payroll_prefix', 'payroll_note_prefix']);
        $this->company->forceFill(['payroll_prefix' => 'NI', 'payroll_note_prefix' => 'NA'])->saveQuietly();

        $this->limpiar[] = function () use ($original, $prefijosOriginales) {
            PayrollTestDocument::query()->where('company_id', $this->companyId)->delete();

            $this->company->forceFill($prefijosOriginales)->saveQuietly();

            if ($original) {
                CompanyConfig::query()
                    ->where('company_id', $this->companyId)
                    ->update(collect($original->getAttributes())
                        ->except(['id', 'created_at', 'updated_at'])
                        ->all());
            } else {
                CompanyConfig::query()->where('company_id', $this->companyId)->delete();
            }
        };
    }

    private function municipio(): int
    {
        $id = Municipality::query()->value('id');

        if ($id) {
            return (int) $id;
        }

        $departamento = DB::table('dian_departments')->value('id')
            ?? DB::table('dian_departments')->insertGetId([
                'code' => '05', 'name' => 'ZZ Departamento',
                'created_at' => now(), 'updated_at' => now(),
            ]);

        $municipioId = DB::table('dian_municipalities')->insertGetId([
            'dian_department_id' => $departamento,
            'code' => '05001', 'name' => 'ZZ Municipio',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->limpiar[] = fn () => DB::table('dian_municipalities')->where('id', $municipioId)->delete();

        return $municipioId;
    }
}
