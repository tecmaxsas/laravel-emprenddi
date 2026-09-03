<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dian\CompanyConfig;
use App\Models\Dian\Municipality;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollSlip;
use App\Models\User;
use App\Services\Dian\PayrollDianSender;
use App\Services\Dian\PayrollDocumentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Envío de nómina electrónica a la DIAN.
 *
 * El payload se contrasta contra el ejemplo oficial de apidian: si la
 * estructura se desvía, la DIAN rechaza el documento y el error llega días
 * después, cuando ya se liquidó la nómina del mes.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PayrollDianTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];
        parent::tearDown();
    }

    /** Colilla completa: empleado, contrato, periodo y valores liquidados. */
    private function colilla(): PayrollSlip
    {
        $this->company->update([
            'payroll_prefix' => 'NI',
            'payroll_next_consecutive' => 100100,
        ]);

        $empleado = Employee::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'document_number' => '41946692'],
            [
                'document_type' => 'cc',
                'first_name' => 'ELIZABETH',
                'last_name' => 'CARDONA',
                'second_last_name' => 'VILLADA',
                'address' => 'BRR LIMONAR MZ 6 CS 3 ET 1',
                'email' => 'somemail@somehost.com',
                'hire_date' => '2018-10-10',
                'payment_method' => 'deposito',
                'bank_name' => 'BANCO DAVIVIENDA',
                'bank_account_type' => 'ahorros',
                'bank_account_number' => '126070603280',
                'status' => 'active',
            ]
        );

        $contrato = EmploymentContract::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'employee_id' => $empleado->id],
            [
                'contract_type' => 'fijo',
                'salary_type' => 'ordinario',
                'position' => 'ZZ Cargo',
                'salary' => 1500000,
                'payment_frequency' => 'mensual',
                'start_date' => '2018-10-10',
                'risk_class' => 'I',
                'status' => 'active',
            ]
        );

        $periodo = PayrollPeriod::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'name' => 'ZZ Julio 2021'],
            [
                'frequency' => 'mensual',
                'start_date' => '2021-07-01',
                'end_date' => '2021-07-31',
                'payment_date' => '2021-03-10',
                'status' => 'draft',
            ]
        );

        $colilla = PayrollSlip::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'payroll_period_id' => $periodo->id, 'employee_id' => $empleado->id],
            [
                'employment_contract_id' => $contrato->id,
                'worked_days' => 30,
                'base_salary' => 1500000,
                'salary_earned' => 750000,
                'transport_allowance' => 115000,
                'total_earnings' => 859000,
                'total_deductions' => 120000,
                'net_pay' => 739000,
                'employee_health' => 60000,
                'employee_pension' => 60000,
            ]
        );

        $this->limpiar[] = function () use ($colilla, $periodo, $contrato, $empleado) {
            DB::table('payroll_slip_lines')->where('payroll_slip_id', $colilla->id)->delete();
            PayrollSlip::withoutGlobalScopes()->whereKey($colilla->id)->delete();
            PayrollPeriod::withoutGlobalScopes()->whereKey($periodo->id)->delete();
            EmploymentContract::withoutGlobalScopes()->whereKey($contrato->id)->delete();
            Employee::withoutGlobalScopes()->whereKey($empleado->id)->forceDelete();
        };

        return $colilla->fresh();
    }

    public function test_el_payload_tiene_la_forma_que_espera_apidian(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['prefix' => 'NI', 'consecutive' => 100100]);

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        // Cabecera
        $this->assertSame(9, $payload['type_document_id'], 'Nómina individual.');
        $this->assertSame('NI', $payload['prefix']);
        $this->assertSame(100100, $payload['consecutive']);
        $this->assertFalse($payload['novelty']['novelty'], 'Documento nuevo, no un ajuste.');

        // Periodo
        $this->assertSame('2018-10-10', $payload['period']['admision_date']);
        $this->assertSame('2021-07-01', $payload['period']['settlement_start_date']);
        $this->assertSame('2021-07-31', $payload['period']['settlement_end_date']);

        // Trabajador
        $this->assertSame(1, $payload['worker']['type_worker_id']);
        $this->assertSame(3, $payload['worker']['payroll_type_document_identification_id'], 'Cédula = 3.');
        $this->assertSame(1, $payload['worker']['type_contract_id'], 'Término fijo = 1.');
        $this->assertSame(41946692, $payload['worker']['identification_number']);
        $this->assertSame('CARDONA', $payload['worker']['surname']);
        $this->assertSame('ELIZABETH', $payload['worker']['first_name']);
        $this->assertFalse($payload['worker']['integral_salarary']);
        $this->assertSame('1500000.00', $payload['worker']['salary']);

        // Pago
        $this->assertSame(10, $payload['payment']['payment_method_id']);
        $this->assertSame('AHORROS', $payload['payment']['account_type'], 'La API lo espera en mayúsculas.');
        $this->assertSame('2021-03-10', $payload['payment_dates'][0]['payment_date']);

        // Devengados y deducciones
        $this->assertSame(30, $payload['accrued']['worked_days']);
        $this->assertSame('750000.00', $payload['accrued']['salary']);
        $this->assertSame('115000.00', $payload['accrued']['transportation_allowance']);
        $this->assertSame('859000.00', $payload['accrued']['accrued_total']);
        $this->assertSame('60000.00', $payload['deductions']['eps_deduction']);
        $this->assertSame('120000.00', $payload['deductions']['deductions_total']);
    }

    /** Los importes viajan como cadena: un float manda "859000" y lo rechazan. */
    public function test_los_importes_van_como_cadena_con_dos_decimales(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['prefix' => 'NI', 'consecutive' => 1]);

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        foreach ([
            $payload['worker']['salary'],
            $payload['accrued']['salary'],
            $payload['accrued']['accrued_total'],
            $payload['deductions']['deductions_total'],
        ] as $importe) {
            $this->assertIsString($importe);
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $importe);
        }
    }

    /** Sin auxilio de transporte el campo no va: en 0 lo declara como devengado. */
    public function test_el_auxilio_de_transporte_se_omite_cuando_no_aplica(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['prefix' => 'NI', 'consecutive' => 1, 'transport_allowance' => 0]);

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        $this->assertArrayNotHasKey('transportation_allowance', $payload['accrued']);
    }

    public function test_una_nomina_aceptada_guarda_el_cune_y_el_qr(): void
    {
        $colilla = $this->colilla();
        $this->configDian();

        Http::fake([
            '*/api/ubl2.1/payroll*' => Http::response([
                'success' => true,
                'cune' => 'abc123cune',
                'ResponseDian' => ['Envelope' => ['Body' => ['SendNominaSyncResponse' => [
                    'SendNominaSyncResult' => ['IsValid' => 'true', 'StatusCode' => '00'],
                ]]]],
            ], 200),
        ]);

        $resultado = app(PayrollDianSender::class)->send($colilla);

        $this->assertTrue($resultado['ok'], $resultado['message']);
        $this->assertSame('abc123cune', $resultado['cune']);

        $colilla->refresh();
        $this->assertSame(PayrollSlip::DIAN_ACCEPTED, $colilla->dian_status);
        $this->assertSame('abc123cune', $colilla->cune);
        $this->assertStringContainsString('abc123cune', $colilla->qr_url);
        $this->assertNotNull($colilla->dian_sent_at);

        // La numeración se reservó y avanzó el consecutivo de la empresa.
        $this->assertSame('NI', $colilla->prefix);
        $this->assertSame(100100, (int) $colilla->consecutive);
        $this->assertSame(100101, (int) $this->company->fresh()->payroll_next_consecutive);
    }

    public function test_un_rechazo_de_la_dian_queda_registrado_con_su_motivo(): void
    {
        $colilla = $this->colilla();
        $this->configDian();

        Http::fake([
            '*/api/ubl2.1/payroll*' => Http::response([
                'ResponseDian' => ['Envelope' => ['Body' => ['SendNominaSyncResponse' => [
                    'SendNominaSyncResult' => [
                        'IsValid' => 'false',
                        'StatusCode' => '99',
                        'StatusDescription' => 'Validación contiene errores',
                        'ErrorMessage' => ['string' => 'Regla: DIAN_XX El municipio es obligatorio'],
                    ],
                ]]]],
            ], 200),
        ]);

        $resultado = app(PayrollDianSender::class)->send($colilla);

        $this->assertFalse($resultado['ok']);
        $this->assertTrue($resultado['reached_dian'], 'La DIAN respondió: no es un fallo de red.');

        $colilla->refresh();
        $this->assertSame(PayrollSlip::DIAN_REJECTED, $colilla->dian_status);
        $this->assertStringContainsString('municipio', $colilla->dian_error_message);
    }

    public function test_no_se_reenvia_una_nomina_ya_aceptada(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['dian_status' => PayrollSlip::DIAN_ACCEPTED, 'cune' => 'ya-aceptada']);

        $this->expectExceptionMessageMatches('/ya fue aceptada/i');
        app(PayrollDianSender::class)->send($colilla->fresh());
    }

    /** Un reintento reusa su número: quemar consecutivos deja huecos. */
    public function test_el_reintento_conserva_el_consecutivo(): void
    {
        $colilla = $this->colilla();
        $this->configDian();

        Http::fake(['*/api/ubl2.1/payroll*' => Http::response(['exception' => 'timeout'], 200)]);

        app(PayrollDianSender::class)->send($colilla);
        $primerNumero = $colilla->fresh()->consecutive;

        app(PayrollDianSender::class)->send($colilla->fresh());

        $this->assertSame((int) $primerNumero, (int) $colilla->fresh()->consecutive,
            'El segundo intento no puede quemar otro consecutivo.');
    }

    /**
     * El comando que trae los catalogos de apidian. Existe para no adivinar
     * los ids del payload, que es como se coleccionan rechazos.
     */
    public function test_el_comando_lista_los_catalogos_de_nomina(): void
    {
        $this->configDian();

        Http::fake([
            '*/reports/master/database' => Http::response([
                'type_contracts' => [
                    ['id' => 1, 'name' => 'Termino fijo'],
                    ['id' => 2, 'name' => 'Termino indefinido'],
                ],
                'type_workers' => [['id' => 1, 'name' => 'Dependiente']],
            ], 200),
        ]);

        $this->artisan('dian:payroll-catalogs', ['--company' => $this->companyId])
            ->expectsOutputToContain('type_contracts')
            ->assertSuccessful();
    }

    /** Si la ruta no es esa, el comando lo dice en vez de fallar en seco. */
    public function test_el_comando_avisa_cuando_la_ruta_no_existe(): void
    {
        $this->configDian();

        Http::fake(['*/reports/master/database' => Http::response(['message' => 'Not Found'], 404)]);

        $this->artisan('dian:payroll-catalogs', ['--company' => $this->companyId])
            ->expectsOutputToContain('--endpoint')
            ->assertFailed();
    }

    /** El trabajador de alto riesgo aporta por otro concepto de pensión. */
    public function test_la_pension_de_alto_riesgo_usa_su_propio_concepto(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['prefix' => 'NI', 'consecutive' => 1]);
        $colilla->employee->update(['high_risk_pension' => true]);

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        $this->assertSame(7, $payload['deductions']['pension_type_law_deductions_id'],
            'Pensión Alto Riesgo Trabajador = 7, no la pensión normal = 5.');
    }

    public function test_la_pension_normal_usa_el_concepto_5(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['prefix' => 'NI', 'consecutive' => 1]);

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        $this->assertSame(5, $payload['deductions']['pension_type_law_deductions_id']);
    }

    /**
     * La DIAN exige que el total de deducciones sea la suma de lo desglosado.
     * Este payload solo desglosa salud y pensión, así que una colilla con
     * fondo de solidaridad o retefuente se rechazaría con un mensaje inútil.
     */
    public function test_no_se_envia_una_colilla_con_deducciones_que_no_se_pueden_desglosar(): void
    {
        $colilla = $this->colilla();
        $colilla->update([
            'prefix' => 'NI',
            'consecutive' => 1,
            // 60.000 + 60.000 + 15.000 de fondo de solidaridad.
            'total_deductions' => 135000,
        ]);

        DB::table('payroll_slip_lines')->insert([
            'payroll_slip_id' => $colilla->id,
            'type' => 'deduction',
            'concept_code' => 'fsp',
            'concept_name' => 'Fondo de solidaridad pensional (1%)',
            'amount' => 15000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(PayrollDocumentBuilder::class)->build($colilla->fresh());
            $this->fail('Debía frenar: los totales no cuadrarían.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('15.000', $e->getMessage());
            $this->assertStringContainsString('Fondo de solidaridad', $e->getMessage(),
                'El mensaje tiene que decir qué deducción sobra.');
        }
    }

    /** Los catálogos sembrados son los de apidian, no los de facturación. */
    public function test_los_catalogos_de_nomina_estan_sembrados_con_los_ids_de_apidian(): void
    {
        $this->assertSame('Quincenal', DB::table('dian_payroll_periods')->find(4)?->name,
            'El ejemplo de Postman manda 4 para un periodo mensual: está mal etiquetado.');
        $this->assertSame('Cédula de ciudadanía', DB::table('dian_payroll_document_types')->find(3)?->name);
        $this->assertSame('Término Fijo', DB::table('dian_type_contracts')->find(1)?->name);
        $this->assertSame('Dependiente', DB::table('dian_type_workers')->find(1)?->name);
        $this->assertSame('No aplica', DB::table('dian_sub_type_workers')->find(1)?->name);

        // El de nómina y el de facturación NO comparten ids.
        $this->assertNotSame(
            DB::table('dian_document_types')->find(11)?->name,
            DB::table('dian_payroll_document_types')->find(11)?->name,
            'Son catálogos distintos: mezclarlos reporta mal el tipo de documento.'
        );
    }

    private function configDian(): void
    {
        $config = CompanyConfig::query()->firstOrNew(['company_id' => $this->companyId]);
        $original = $config->exists ? $config->replicate() : null;

        $config->fill([
            'api_url' => 'https://apidian.test',
            'api_token' => 'zz-token',
            'company_registered' => true,
            // Se usa un municipio real si el catálogo está sembrado; en dev
            // viene vacío y el payload lo lleva en null, que es justo lo que
            // la DIAN rechazaría en producción.
            'dian_municipality_id' => Municipality::query()->value('id'),
        ])->save();

        $this->limpiar[] = function () use ($config, $original) {
            if ($original) {
                $config->fill($original->getAttributes())->save();
            } else {
                $config->delete();
            }
        };
    }
}
