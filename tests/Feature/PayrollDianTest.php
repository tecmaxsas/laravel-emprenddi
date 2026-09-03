<?php

namespace Tests\Feature;

use App\Filament\App\Pages\DianSettings;
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
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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
        // Cadena, no entero: es lo que manda el set de pruebas de la DIAN y
        // evita problemas con documentos largos.
        $this->assertSame('41946692', $payload['worker']['identification_number']);
        $this->assertSame('CARDONA', $payload['worker']['surname']);
        $this->assertSame('ELIZABETH', $payload['worker']['first_name']);
        $this->assertFalse($payload['worker']['integral_salarary']);
        $this->assertSame('1500000.00', $payload['worker']['salary']);

        // Pago
        // Consignación bancaria: el empleado cobra en cuenta de ahorros. El
        // ejemplo de Postman manda 10, que es efectivo.
        $this->assertSame(42, $payload['payment']['payment_method_id']);
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

    /**
     * Correcciones que salieron del set de pruebas de la DIAN.
     *
     * worked_time son los dias del PERIODO, no la antiguedad: el ejemplo de
     * la DIAN manda 30 para enero. El primer ejemplo de Postman traia 785 con
     * un periodo de un mes, que no cuadra con ninguna lectura.
     */
    public function test_el_tiempo_laborado_son_los_dias_del_periodo(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['prefix' => 'NI', 'consecutive' => 1]);

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        $this->assertSame(30, $payload['period']['worked_time'],
            'Los días trabajados del periodo, no los de antigüedad en la empresa.');
        $this->assertSame(30, $payload['accrued']['worked_days']);
    }

    public function test_el_payload_lleva_fecha_hora_y_codigo_del_trabajador(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['prefix' => 'NI', 'consecutive' => 1]);

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        $this->assertSame(now()->toDateString(), $payload['date']);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $payload['time']);
        // El set de pruebas lo trae en los dos sitios.
        $this->assertSame('41946692', $payload['worker_code']);
        $this->assertSame('41946692', $payload['worker']['worker_code']);
        $this->assertSame('41946692', $payload['worker']['identification_number']);
    }

    /**
     * El ejemplo manda 10 para un pago a cuenta de ahorros, pero el 10 es
     * EFECTIVO: reportaria una consignacion como pago en efectivo.
     */
    public function test_el_deposito_en_cuenta_no_se_reporta_como_efectivo(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['prefix' => 'NI', 'consecutive' => 1]);

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        $this->assertSame(42, $payload['payment']['payment_method_id'],
            'Consignación bancaria = 42. El 10 es efectivo.');

        $colilla->employee->update(['payment_method' => 'efectivo']);
        $this->assertSame(10, app(PayrollDocumentBuilder::class)
            ->build($colilla->fresh())['payment']['payment_method_id']);

        $colilla->employee->update(['payment_method' => 'cheque']);
        $this->assertSame(20, app(PayrollDocumentBuilder::class)
            ->build($colilla->fresh())['payment']['payment_method_id']);
    }

    /** Con TestSetId configurado, el envio va al set de pruebas. */
    public function test_el_envio_usa_el_set_de_pruebas_cuando_esta_configurado(): void
    {
        $colilla = $this->colilla();
        $this->configDian();

        CompanyConfig::query()->where('company_id', $this->companyId)
            ->update(['payroll_test_set_id' => '4177964d-de81-4178-9d66-bb2fc05d9d92']);

        Http::fake([
            '*' => Http::response([
                'cune' => 'cune-prueba',
                'ResponseDian' => ['Envelope' => ['Body' => ['SendNominaSyncResponse' => [
                    'SendNominaSyncResult' => ['IsValid' => 'true', 'StatusCode' => '00'],
                ]]]],
            ], 200),
        ]);

        app(PayrollDianSender::class)->send($colilla);

        Http::assertSent(fn ($request) => str_contains(
            $request->url(),
            '/api/ubl2.1/payroll/4177964d-de81-4178-9d66-bb2fc05d9d92'
        ));
    }

    /** Sin TestSetId el envio va a produccion, sin sufijo en la ruta. */
    public function test_sin_set_de_pruebas_el_envio_va_a_produccion(): void
    {
        $colilla = $this->colilla();
        $this->configDian();

        Http::fake([
            '*' => Http::response([
                'cune' => 'cune-produccion',
                'ResponseDian' => ['Envelope' => ['Body' => ['SendNominaSyncResponse' => [
                    'SendNominaSyncResult' => ['IsValid' => 'true', 'StatusCode' => '00'],
                ]]]],
            ], 200),
        ]);

        app(PayrollDianSender::class)->send($colilla);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/ubl2.1/payroll'));
    }

    /**
     * El set de pruebas de la habilitacion, igual que en facturacion: payload
     * de muestra con prefijo SETP y su propio consecutivo, para no gastar
     * numeracion de produccion ni tocar la nomina real.
     */
    public function test_el_set_de_pruebas_se_envia_con_el_testsetid(): void
    {
        $this->configDian();
        CompanyConfig::query()->where('company_id', $this->companyId)->update([
            'payroll_test_set_id' => '4177964d-de81-4178-9d66-bb2fc05d9d92',
            'payroll_software_configured' => true,
            'payroll_test_consecutive' => 1,
        ]);

        Http::fake([
            '*' => Http::response([
                'cune' => 'cune-set-pruebas',
                'ResponseDian' => ['Envelope' => ['Body' => ['SendNominaSyncResponse' => [
                    'SendNominaSyncResult' => ['IsValid' => 'true', 'StatusCode' => '00'],
                ]]]],
            ], 200),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        Livewire::test(DianSettings::class)->call('sendTestPayroll');

        Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            return str_contains($request->url(), '/api/ubl2.1/payroll/4177964d-de81-4178-9d66-bb2fc05d9d92')
                && $cuerpo['prefix'] === 'SETP'
                && $cuerpo['consecutive'] === 1
                && $cuerpo['type_document_id'] === 9;
        });

        // El consecutivo avanza: la DIAN no acepta dos con el mismo número.
        $this->assertSame(2, (int) CompanyConfig::query()
            ->where('company_id', $this->companyId)->value('payroll_test_consecutive'));
    }

    /** El consecutivo avanza incluso si la DIAN rechaza: el número se quema. */
    public function test_el_consecutivo_de_prueba_avanza_aunque_la_dian_rechace(): void
    {
        $this->configDian();
        CompanyConfig::query()->where('company_id', $this->companyId)->update([
            'payroll_test_set_id' => 'zz-set',
            'payroll_software_configured' => true,
            'payroll_test_consecutive' => 7,
        ]);

        Http::fake([
            '*' => Http::response([
                'ResponseDian' => ['Envelope' => ['Body' => ['SendNominaSyncResponse' => [
                    'SendNominaSyncResult' => ['IsValid' => 'false', 'StatusCode' => '99',
                        'StatusDescription' => 'Validación contiene errores'],
                ]]]],
            ], 200),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        Livewire::test(DianSettings::class)->call('sendTestPayroll');

        $this->assertSame(8, (int) CompanyConfig::query()
            ->where('company_id', $this->companyId)->value('payroll_test_consecutive'));
    }

    /** Sin TestSetId no se envía nada: no tiene a dónde ir. */
    public function test_no_se_envia_el_set_de_pruebas_sin_testsetid(): void
    {
        $this->configDian();
        CompanyConfig::query()->where('company_id', $this->companyId)->update([
            'payroll_test_set_id' => null,
            'payroll_software_configured' => true,
        ]);

        Http::fake();

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        Livewire::test(DianSettings::class)->call('sendTestPayroll');

        Http::assertNothingSent();
    }

    /** Sin el software de nómina registrado, la DIAN rechazaría todo. */
    public function test_no_se_envia_el_set_sin_registrar_el_software_de_nomina(): void
    {
        $this->configDian();
        CompanyConfig::query()->where('company_id', $this->companyId)->update([
            'payroll_test_set_id' => 'zz-set',
            'payroll_software_configured' => false,
        ]);

        Http::fake();

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        Livewire::test(DianSettings::class)->call('sendTestPayroll');

        Http::assertNothingSent();
    }

    /**
     * El portal de la DIAN muestra el ID del software y el TestSetId juntos y
     * se confunden. El del software no sirve y apidian responde 500.
     */
    public function test_avisa_si_el_testsetid_es_en_realidad_el_id_del_software(): void
    {
        $this->configDian();
        CompanyConfig::query()->where('company_id', $this->companyId)->update([
            'software_payroll_id' => 'f8a07a65-c7d1-4a58-bf18-be19aecba181',
            'payroll_test_set_id' => 'f8a07a65-c7d1-4a58-bf18-be19aecba181',
            'payroll_software_configured' => true,
        ]);

        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        Livewire::test(DianSettings::class)->call('sendTestPayroll');

        Http::assertNothingSent();
    }

    /** Sin municipio apidian revienta con un 500 que no dice nada. */
    public function test_no_se_envia_el_set_sin_municipio_configurado(): void
    {
        $this->configDian();
        CompanyConfig::query()->where('company_id', $this->companyId)->update([
            'payroll_test_set_id' => '4177964d-de81-4178-9d66-bb2fc05d9d92',
            'payroll_software_configured' => true,
            'dian_municipality_id' => null,
        ]);

        Http::fake();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        Livewire::test(DianSettings::class)->call('sendTestPayroll');

        Http::assertNothingSent();
    }

    /**
     * Un municipio DIAN real. El catálogo puede no estar sembrado en dev, y la
     * empresa lo necesita: sin él apidian responde 500.
     */
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
            'code' => '05001',
            'name' => 'ZZ Municipio',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->limpiar[] = fn () => DB::table('dian_municipalities')->where('id', $municipioId)->delete();

        return $municipioId;
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
            'dian_municipality_id' => $this->municipio(),
            // Explícito: si lo dejara como está, heredaría el de otra prueba.
            'payroll_test_set_id' => null,
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
