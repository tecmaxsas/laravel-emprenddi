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
                // 750.000 + 109.000 = 859.000. Los devengados tienen que
                // cuadrar con el total: la DIAN lo valida.
                'transport_allowance' => 109000,
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
        $this->assertSame('109000.00', $payload['accrued']['transportation_allowance']);
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
        // El total baja con el auxilio: los devengados tienen que seguir
        // cuadrando o el envío se para antes de llegar a la DIAN.
        $colilla->update([
            'prefix' => 'NI',
            'consecutive' => 1,
            'transport_allowance' => 0,
            'total_earnings' => 750000,
        ]);

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
     * El fondo de solidaridad es obligatorio por ley desde 4 SMLMV, así que no
     * es un caso raro: si no se puede reportar, los salarios altos no se
     * pueden transmitir.
     */
    public function test_el_fondo_de_solidaridad_se_reporta_con_su_concepto(): void
    {
        $colilla = $this->colilla();
        $colilla->update([
            'prefix' => 'NI',
            'consecutive' => 1,
            'solidarity_fund' => 15000,
            // 60.000 salud + 60.000 pensión + 15.000 fondo.
            'total_deductions' => 135000,
        ]);

        $this->lineaDeDeduccion($colilla, 'fsp', 'Fondo de solidaridad pensional (1%)', 15000);

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        $this->assertSame(9, $payload['deductions']['fondossp_type_law_deductions_id']);
        $this->assertSame('15000.00', $payload['deductions']['fondosp_deduction_SP']);
        $this->assertSame('135000.00', $payload['deductions']['deductions_total']);
    }

    public function test_la_retencion_en_la_fuente_va_en_su_propio_campo(): void
    {
        $colilla = $this->colilla();
        $colilla->update([
            'prefix' => 'NI',
            'consecutive' => 1,
            'total_deductions' => 200000,
        ]);

        $this->lineaDeDeduccion($colilla, 'retencion_fuente', 'Retención en la fuente', 80000);

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        $this->assertSame('80000.00', $payload['deductions']['withholding_at_source']);
        $this->assertArrayNotHasKey('other_deductions', $payload['deductions']);
    }

    /**
     * Cada descuento en el campo que le corresponde. El estándar nombra las
     * libranzas, los embargos, la cooperativa y el aporte voluntario; lo que
     * no nombra cae en "otras deducciones".
     */
    public function test_cada_descuento_va_al_campo_que_le_corresponde(): void
    {
        $colilla = $this->colilla();
        $colilla->update([
            'prefix' => 'NI',
            'consecutive' => 1,
            'total_deductions' => 235000,
        ]);

        $this->lineaDeDeduccion($colilla, 'prestamo', 'Préstamo / libranza', 20000);
        $this->lineaDeDeduccion($colilla, 'embargo', 'Embargo judicial', 30000);
        $this->lineaDeDeduccion($colilla, 'cooperativa', 'Fondo de empleados', 15000);
        $this->lineaDeDeduccion($colilla, 'otro_descuento', 'Descuento varios', 50000);

        $deducciones = app(PayrollDocumentBuilder::class)->build($colilla->fresh())['deductions'];

        $this->assertSame('20000.00', $deducciones['orders'][0]['deduction']);
        $this->assertSame('30000.00', $deducciones['tax_liens']);
        $this->assertSame('15000.00', $deducciones['cooperative']);
        $this->assertSame('50000.00', $deducciones['other_deductions'][0]['other_deduction']);
        $this->assertSame('235000.00', $deducciones['deductions_total']);
    }

    /** Sin deducciones extra, los campos opcionales no viajan. */
    public function test_los_conceptos_en_cero_no_se_mandan(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['prefix' => 'NI', 'consecutive' => 1]);

        $deducciones = app(PayrollDocumentBuilder::class)->build($colilla->fresh())['deductions'];

        $this->assertArrayNotHasKey('fondossp_type_law_deductions_id', $deducciones);
        $this->assertArrayNotHasKey('withholding_at_source', $deducciones);
        $this->assertArrayNotHasKey('other_deductions', $deducciones);
    }

    /**
     * La DIAN exige que el total sea la suma de lo detallado. Si la colilla no
     * cuadra consigo misma, el envío se para: llegar a la DIAN con el total
     * mal da un rechazo días después, con el consecutivo ya gastado.
     */
    public function test_no_se_envia_una_colilla_cuyas_deducciones_no_cuadran(): void
    {
        $colilla = $this->colilla();
        $colilla->update([
            'prefix' => 'NI',
            'consecutive' => 1,
            // 60.000 + 60.000 de las líneas, pero el total dice 200.000.
            'total_deductions' => 200000,
        ]);

        try {
            app(PayrollDocumentBuilder::class)->build($colilla->fresh());
            $this->fail('Debía frenar: los totales no cuadran.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('200.000', $e->getMessage());
            $this->assertStringContainsString('80.000', $e->getMessage(),
                'El mensaje tiene que decir de cuánto es la diferencia.');
        }
    }

    private function lineaDeDeduccion(PayrollSlip $colilla, string $codigo, string $nombre, float $monto): void
    {
        DB::table('payroll_slip_lines')->insert([
            'payroll_slip_id' => $colilla->id,
            'type' => 'deduction',
            'concept_code' => $codigo,
            'concept_name' => $nombre,
            'amount' => $monto,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    public function test_el_payload_lleva_fecha_de_emision_y_codigo_del_trabajador(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['prefix' => 'NI', 'consecutive' => 1]);

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        // La fecha del documento va SOLO en period.issue_date. Mandar `date` y
        // `time` al nivel superior hacia que apidian devolviera HTTP 500 sin
        // detalle; el payload que si procesa no los trae.
        $this->assertArrayNotHasKey('date', $payload);
        $this->assertArrayNotHasKey('time', $payload);
        $this->assertSame(now()->toDateString(), $payload['period']['issue_date']);

        // El set de pruebas lo trae en los dos sitios.
        $this->assertSame('41946692', $payload['worker_code']);
        $this->assertSame('41946692', $payload['worker']['worker_code']);
        $this->assertSame('41946692', $payload['worker']['identification_number']);
    }

    /**
     * apidian revienta con un null en cualquiera de estos campos y el 500 que
     * devuelve no dice cual es. Una empresa a la que le falte el telefono o la
     * direccion tiene que poder enviar nomina igual.
     */
    public function test_los_datos_del_establecimiento_nunca_van_en_null(): void
    {
        $colilla = $this->colilla();
        $colilla->update(['prefix' => 'NI', 'consecutive' => 1]);

        // Corre contra la base de desarrollo: se deja la empresa como estaba.
        $original = $this->company->only(['address', 'phone', 'email']);
        $this->limpiar[] = fn () => $this->company->forceFill($original)->saveQuietly();

        $this->company->forceFill([
            'address' => null,
            'phone' => null,
            'email' => null,
        ])->saveQuietly();

        $payload = app(PayrollDocumentBuilder::class)->build($colilla->fresh());

        foreach (['establishment_name', 'establishment_address', 'establishment_phone', 'establishment_email'] as $campo) {
            $this->assertNotNull($payload[$campo], "{$campo} llegó en null: apidian responde Server Error.");
            $this->assertNotSame('', $payload[$campo], "{$campo} llegó vacío.");
        }
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
                && $cuerpo['prefix'] === 'NI'
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

    /**
     * La DIAN pone el motivo real en ErrorMessage, no en StatusDescription:
     * ésta última suele ser un genérico "Documento con errores en campos
     * mandatorios". Mostrar sólo la descripción deja un rechazo sin motivo, y
     * sin motivo no hay nada que corregir.
     */
    public function test_el_rechazo_muestra_las_reglas_que_fallaron(): void
    {
        $colilla = $this->colilla();
        $this->configDian();

        Http::fake([
            '*' => Http::response([
                'ResponseDian' => ['Envelope' => ['Body' => ['SendNominaSyncResponse' => [
                    'SendNominaSyncResult' => [
                        'IsValid' => 'false',
                        'StatusCode' => '99',
                        'StatusDescription' => 'Validación contiene errores en campos mandatorios',
                        'ErrorMessage' => ['string' => [
                            'Regla: DIAN72 Rechazo: El campo NumeroSecuenciaXML no cumple',
                            'Regla: FAJ42 Rechazo: Fecha de emisión fuera de rango',
                        ]],
                    ],
                ]]]],
            ], 200),
        ]);

        $resultado = app(PayrollDianSender::class)->send($colilla);

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('DIAN72', $resultado['message']);
        $this->assertStringContainsString('FAJ42', $resultado['message']);
        $this->assertStringContainsString('DIAN72', (string) $colilla->fresh()->dian_error_message,
            'El motivo tiene que quedar guardado en la colilla, no sólo en la notificación.');
    }

    /**
     * Al set de pruebas la DIAN contesta por SendTestSetAsync: acusa recibo y
     * valida después. Esa respuesta no trae IsValid, y leerla como si lo
     * trajera marcaba rechazada una nómina que la DIAN todavía no había
     * mirado.
     */
    public function test_el_acuse_asincrono_no_se_toma_como_rechazo(): void
    {
        $colilla = $this->colilla();
        $this->configDian();

        Http::fake([
            '*' => Http::response([
                'message' => 'Nomina Individual #NI1 generada con éxito',
                'ResponseDian' => ['Envelope' => ['Body' => ['SendTestSetAsyncResponse' => [
                    'SendTestSetAsyncResult' => ['ZipKey' => 'abc-123', 'ErrorMessageList' => null],
                ]]]],
            ], 200),
        ]);

        $resultado = app(PayrollDianSender::class)->send($colilla, 'zz-set');

        $this->assertTrue($resultado['ok'], 'Un acuse de recibo no es un rechazo.');
        $this->assertStringContainsString('abc-123', $resultado['message']);
        $this->assertSame(PayrollSlip::DIAN_SENT, $colilla->fresh()->dian_status,
            'Queda enviada: la DIAN aún no dijo si la acepta.');
    }

    /** Si el archivo viene mal armado, la DIAN lo dice en el mismo acuse. */
    public function test_los_errores_del_acuse_asincrono_si_son_rechazo(): void
    {
        $colilla = $this->colilla();
        $this->configDian();

        Http::fake([
            '*' => Http::response([
                'ResponseDian' => ['Envelope' => ['Body' => ['SendTestSetAsyncResponse' => [
                    'SendTestSetAsyncResult' => ['ErrorMessageList' => ['string' => [
                        'Regla: DIAN30 Rechazo: TestSetId no corresponde',
                    ]]],
                ]]]],
            ], 200),
        ]);

        $resultado = app(PayrollDianSender::class)->send($colilla, 'zz-set');

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('DIAN30', $resultado['message']);
        $this->assertSame(PayrollSlip::DIAN_REJECTED, $colilla->fresh()->dian_status);
    }

    /**
     * Un documento que certifica un pago que todavía no ocurrió. La DIAN lo
     * rechaza y el rechazo llega días después, con el consecutivo ya quemado.
     */
    public function test_no_se_envia_una_nomina_de_un_periodo_sin_cerrar(): void
    {
        $colilla = $this->colilla();
        $colilla->period->update([
            'start_date' => now()->startOfMonth(),
            'end_date' => now()->addMonth()->endOfMonth(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no ha cerrado/');

        app(PayrollDocumentBuilder::class)->build($colilla->fresh());
    }

    /**
     * El set de pruebas liquidaba el mes en curso: un documento emitido hoy
     * para un periodo que termina dentro de tres semanas. La DIAN lo rechazó
     * dos veces por eso.
     */
    public function test_la_nomina_de_prueba_liquida_un_periodo_ya_cerrado(): void
    {
        $this->configDian();

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        $pagina = new DianSettings;
        $metodo = new \ReflectionMethod($pagina, 'buildTestPayrollPayload');
        $metodo->setAccessible(true);
        $payload = $metodo->invoke($pagina, 1, 'NI');

        $fin = $payload['period']['settlement_end_date'];
        $emision = $payload['period']['issue_date'];

        $this->assertLessThanOrEqual($emision, $fin,
            'El periodo tiene que haber terminado antes de la fecha de emisión.');
        $this->assertLessThanOrEqual($emision, $payload['payment_dates'][0]['payment_date']);
    }

    /**
     * Regla NIE023: la DIAN valida el atributo Ambiente del documento y en el
     * set de pruebas exige habilitación. apidian lo saca de la configuración
     * de la empresa, no del payload, así que hay que asegurarlo antes.
     */
    public function test_el_set_de_pruebas_pone_la_nomina_en_habilitacion(): void
    {
        $this->configDian();
        CompanyConfig::query()->where('company_id', $this->companyId)->update([
            'payroll_test_set_id' => 'zz-set',
            'payroll_software_configured' => true,
            'environment' => CompanyConfig::ENV_PRODUCTION,
            'payroll_environment' => CompanyConfig::ENV_PRODUCTION,
        ]);

        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        Livewire::test(DianSettings::class)->call('sendTestPayroll');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/config/environment')) {
                return false;
            }

            // El de facturación se conserva: puede estar ya en producción y
            // son dos habilitaciones independientes.
            return $request['payroll_type_environment_id'] === CompanyConfig::ENV_TEST
                && $request['type_environment_id'] === CompanyConfig::ENV_PRODUCTION;
        });

        $this->assertSame(CompanyConfig::ENV_TEST, (int) CompanyConfig::query()
            ->where('company_id', $this->companyId)->value('payroll_environment'));
    }

    /** Si ya está en habilitación no se vuelve a pedir el cambio. */
    public function test_no_se_repite_el_cambio_de_ambiente_si_ya_esta_en_habilitacion(): void
    {
        $this->configDian();
        CompanyConfig::query()->where('company_id', $this->companyId)->update([
            'payroll_test_set_id' => 'zz-set',
            'payroll_software_configured' => true,
            'payroll_environment' => CompanyConfig::ENV_TEST,
        ]);

        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        Livewire::test(DianSettings::class)->call('sendTestPayroll');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/config/environment'));
    }

    /**
     * Pasar la nómina a producción no puede devolver la facturación a pruebas:
     * el endpoint pide los tres ambientes juntos y es fácil pisarlos.
     */
    public function test_el_ambiente_de_nomina_no_pisa_el_de_facturacion(): void
    {
        $this->configDian();
        CompanyConfig::query()->where('company_id', $this->companyId)->update([
            'environment' => CompanyConfig::ENV_PRODUCTION,
            'payroll_environment' => CompanyConfig::ENV_TEST,
        ]);

        Http::fake(['*' => Http::response(['message' => 'ok'], 200)]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        Livewire::test(DianSettings::class)
            ->set('data.payroll_environment', CompanyConfig::ENV_PRODUCTION)
            ->call('savePayrollEnvironment');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/config/environment')
            && $request['type_environment_id'] === CompanyConfig::ENV_PRODUCTION
            && $request['payroll_type_environment_id'] === CompanyConfig::ENV_PRODUCTION);

        $this->assertSame(CompanyConfig::ENV_PRODUCTION, (int) CompanyConfig::query()
            ->where('company_id', $this->companyId)->value('payroll_environment'));
    }

    /**
     * La DIAN no admite dos nóminas del mismo trabajador para el mismo
     * periodo. Los envíos de prueba salían idénticos salvo el consecutivo, así
     * que el primero se aceptaba y los siguientes se rechazaban.
     */
    public function test_cada_nomina_de_prueba_es_un_documento_distinto(): void
    {
        $this->configDian();

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        $pagina = new DianSettings;
        $metodo = new \ReflectionMethod($pagina, 'buildTestPayrollPayload');
        $metodo->setAccessible(true);

        $huellas = [];
        $trabajadores = [];

        foreach (range(1, 10) as $consecutivo) {
            $payload = $metodo->invoke($pagina, $consecutivo, 'NI');

            $huellas[] = $payload['worker']['identification_number']
                .'|'.$payload['period']['settlement_start_date']
                .'|'.$payload['period']['settlement_end_date'];

            $trabajadores[] = $payload['worker']['identification_number'];

            // Cada periodo tiene que seguir cerrado y el pago dentro de él.
            $this->assertLessThanOrEqual(
                $payload['period']['issue_date'],
                $payload['period']['settlement_end_date'],
                "El documento {$consecutivo} liquida un periodo que aún no cierra.",
            );
            $this->assertSame(
                $payload['period']['settlement_end_date'],
                $payload['payment_dates'][0]['payment_date'],
            );
            $this->assertSame((string) $payload['worker']['identification_number'], $payload['worker_code']);
        }

        $this->assertCount(10, array_unique($huellas),
            'Dos documentos del set repiten trabajador y periodo: la DIAN rechaza el segundo.');
        $this->assertCount(10, array_unique($trabajadores));
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
    /**
     * El prefijo de las pruebas tiene que ser uno registrado en apidian.
     *
     * Mandar SETP cuando el rango registrado es NI hace que apidian no
     * encuentre la resolucion de nomina y responda un 500 sin detalle, que es
     * imposible de diagnosticar desde afuera.
     */
    public function test_el_set_de_pruebas_usa_el_prefijo_registrado(): void
    {
        $this->configDian();
        CompanyConfig::query()->where('company_id', $this->companyId)->update([
            'payroll_test_set_id' => 'zz-set',
            'payroll_software_configured' => true,
        ]);
        $this->company->update(['payroll_prefix' => 'NI']);

        Http::fake([
            '*' => Http::response([
                'cune' => 'zz',
                'ResponseDian' => ['Envelope' => ['Body' => ['SendNominaSyncResponse' => [
                    'SendNominaSyncResult' => ['IsValid' => 'true', 'StatusCode' => '00'],
                ]]]],
            ], 200),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        Livewire::test(DianSettings::class)->call('sendTestPayroll');

        // El flujo puede mandar más de una petición (p. ej. el ambiente), así
        // que se busca la de la nómina en vez de asumir que sólo hay una.
        Http::assertSent(fn ($request) => ($request->data()['prefix'] ?? null) === 'NI');
    }

    /** El payload enviado queda a la vista: apidian oculta el detalle de sus 500. */
    public function test_el_payload_enviado_queda_disponible_para_reproducirlo(): void
    {
        $this->configDian();
        CompanyConfig::query()->where('company_id', $this->companyId)->update([
            'payroll_test_set_id' => 'zz-set',
            'payroll_software_configured' => true,
        ]);
        $this->company->update(['payroll_prefix' => 'NI']);

        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company->refresh());

        $pagina = Livewire::test(DianSettings::class)->call('sendTestPayroll');

        $payload = $pagina->get('lastPayrollTestPayload');

        $this->assertIsArray($payload, 'Sin el payload no hay forma de reproducir el fallo.');
        $this->assertSame(9, $payload['type_document_id']);
        $this->assertSame('NI', $payload['prefix']);
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

        // Se restaura por query y no con fill()->save() sobre $config: ese
        // objeto se quedó con los valores del fill de arriba, así que las
        // columnas que la prueba cambió después no salían dirty y no se
        // escribían. La fila quedaba modificada para la prueba siguiente.
        $this->limpiar[] = function () use ($original) {
            if (! $original) {
                CompanyConfig::query()->where('company_id', $this->companyId)->delete();

                return;
            }

            CompanyConfig::query()
                ->where('company_id', $this->companyId)
                ->update(collect($original->getAttributes())
                    ->except(['id', 'created_at', 'updated_at'])
                    ->all());
        };
    }
}
