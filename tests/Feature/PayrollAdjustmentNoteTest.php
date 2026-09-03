<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dian\CompanyConfig;
use App\Models\Dian\Municipality;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\PayrollAdjustmentNote;
use App\Models\PayrollParameter;
use App\Models\PayrollPeriod;
use App\Models\PayrollSlip;
use App\Models\User;
use App\Services\Dian\PayrollAdjustmentNoteSender;
use App\Services\Dian\PayrollCatalog;
use App\Services\Payroll\PayrollEngine;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Corrección de una nómina que la DIAN ya aceptó.
 *
 * Reenviarla no se puede: la DIAN no admite dos veces el mismo documento. La
 * única salida legal es una nota de ajuste que apunte a la original por su
 * CUNE, así que lo que se prueba aquí es que ese enlace no se pierda.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class PayrollAdjustmentNoteTest extends TestCase
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

    public function test_la_nota_apunta_a_la_nomina_por_su_cune(): void
    {
        $colilla = $this->colillaAceptada();
        $this->configDian();

        Http::fake(['*' => $this->respuestaAceptada()]);

        $resultado = app(PayrollAdjustmentNoteSender::class)->emit($colilla);

        $this->assertTrue($resultado['ok'], $resultado['message']);

        $nota = $resultado['note'];
        $this->assertSame('cune-original', $nota->predecessor_cune);
        $this->assertSame('NI', $nota->predecessor_prefix);
        $this->assertSame(500, $nota->predecessor_consecutive);

        // Numeración propia, distinta a la de la nómina.
        $this->assertSame('NA', $nota->prefix);
        $this->assertNotSame($colilla->consecutive, $nota->consecutive);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'payroll-adjust-note')) {
                return false;
            }

            return $request['type_document_id'] === 10
                && $request['type_note'] === PayrollCatalog::TYPE_NOTE_REEMPLAZAR
                && $request['predecessor']['predecessor_number'] === 500
                && $request['predecessor']['predecessor_cune'] === 'cune-original';
        });
    }

    /** La nota de eliminación va a otro endpoint. */
    public function test_la_anulacion_usa_el_endpoint_de_eliminacion(): void
    {
        $colilla = $this->colillaAceptada();
        $this->configDian();

        Http::fake(['*' => $this->respuestaAceptada()]);

        app(PayrollAdjustmentNoteSender::class)->emit($colilla, PayrollCatalog::TYPE_NOTE_ELIMINAR);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'payroll-delete-note')
            && $request['type_note'] === PayrollCatalog::TYPE_NOTE_ELIMINAR);
    }

    /** Sin CUNE no hay documento que corregir: la DIAN respondería NIAE191a. */
    public function test_no_se_ajusta_una_nomina_que_la_dian_no_ha_aceptado(): void
    {
        $colilla = $this->colillaAceptada();
        $colilla->update(['cune' => null]);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no tiene CUNE|no la ha aceptado/');

        app(PayrollAdjustmentNoteSender::class)->emit($colilla->fresh());
    }

    /** Una nómina anulada ya no admite más ajustes. */
    public function test_no_se_ajusta_una_nomina_ya_anulada(): void
    {
        $colilla = $this->colillaAceptada();
        $this->configDian();

        PayrollAdjustmentNote::create([
            'company_id' => $this->companyId,
            'payroll_slip_id' => $colilla->id,
            'type_note' => PayrollCatalog::TYPE_NOTE_ELIMINAR,
            'dian_status' => PayrollAdjustmentNote::DIAN_ACCEPTED,
        ]);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/anulada/');

        app(PayrollAdjustmentNoteSender::class)->emit($colilla);
    }

    public function test_sin_prefijo_de_notas_no_se_emite(): void
    {
        $colilla = $this->colillaAceptada();
        $this->configDian();
        $this->company->update(['payroll_note_prefix' => null]);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/prefijo de notas/');

        app(PayrollAdjustmentNoteSender::class)->emit($colilla);
    }

    /**
     * Re-liquidar borra los desprendibles para recalcularlos. Si eso se lleva
     * por delante el CUNE, la nómina reportada se queda sin forma de
     * corregirse: la nota de ajuste no tendría a qué apuntar.
     */
    public function test_reliquidar_no_borra_el_rastro_de_lo_ya_reportado(): void
    {
        $colilla = $this->colillaAceptada();
        $periodo = $colilla->period;

        app(PayrollEngine::class)->liquidate($periodo);

        $recalculada = PayrollSlip::withoutGlobalScopes()
            ->where('payroll_period_id', $periodo->id)
            ->where('employee_id', $colilla->employee_id)
            ->first();

        $this->assertNotNull($recalculada, 'La re-liquidación no generó desprendible para el empleado.');
        $this->assertSame('cune-original', $recalculada->cune,
            'Se perdió el CUNE: la nómina reportada ya no se podría corregir.');
        $this->assertSame('NI', $recalculada->prefix);
        $this->assertSame(500, $recalculada->consecutive);
    }

    /** Si el neto cambió, lo reportado y lo liquidado ya no coinciden. */
    public function test_reliquidar_marca_la_nomina_que_quedo_desalineada(): void
    {
        $colilla = $this->colillaAceptada();

        // Un neto que la re-liquidación no va a reproducir.
        $colilla->update(['net_pay' => 1]);

        app(PayrollEngine::class)->liquidate($colilla->period);

        $recalculada = PayrollSlip::withoutGlobalScopes()
            ->where('payroll_period_id', $colilla->payroll_period_id)
            ->where('employee_id', $colilla->employee_id)
            ->first();

        $this->assertTrue((bool) $recalculada->dian_needs_adjustment,
            'Sin la marca no hay forma de saber qué nóminas quedaron desalineadas con la DIAN.');
    }

    private function respuestaAceptada(): PromiseInterface
    {
        return Http::response([
            'cune' => 'cune-de-la-nota',
            'ResponseDian' => ['Envelope' => ['Body' => ['SendNominaAjusteSyncResponse' => [
                'SendNominaAjusteSyncResult' => [
                    'IsValid' => 'true',
                    'StatusCode' => '00',
                    'ErrorMessage' => ['_attributes' => ['nil' => 'true']],
                ],
            ]]]],
        ], 200);
    }

    private function colillaAceptada(): PayrollSlip
    {
        $this->parametrosDelAnio();

        $this->company->update([
            'payroll_prefix' => 'NI',
            'payroll_note_prefix' => 'NA',
            'payroll_note_next_consecutive' => 1,
        ]);

        $empleado = Employee::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'document_number' => 'ZZ77001'],
            [
                'document_type' => 'cc',
                'first_name' => 'MARIA',
                'last_name' => 'LOPEZ',
                'address' => 'CALLE 1',
                'email' => 'zz@zz.co',
                'hire_date' => '2024-01-01',
                'payment_method' => 'deposito',
                'bank_name' => 'BANCO',
                'bank_account_type' => 'ahorros',
                'bank_account_number' => '1',
                'status' => 'active',
            ]
        );

        $contrato = EmploymentContract::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'employee_id' => $empleado->id],
            [
                'contract_type' => 'indefinido',
                'salary_type' => 'ordinario',
                'position' => 'ZZ',
                'salary' => 1500000,
                'payment_frequency' => 'mensual',
                'start_date' => '2024-01-01',
                'risk_class' => 'I',
                'status' => 'active',
            ]
        );

        $periodo = PayrollPeriod::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'name' => 'ZZ Ajuste'],
            [
                'frequency' => 'mensual',
                'start_date' => now()->subMonthNoOverflow()->startOfMonth(),
                'end_date' => now()->subMonthNoOverflow()->endOfMonth(),
                'payment_date' => now()->subMonthNoOverflow()->endOfMonth(),
                'status' => 'draft',
            ]
        );

        $colilla = PayrollSlip::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->companyId, 'payroll_period_id' => $periodo->id, 'employee_id' => $empleado->id],
            [
                'employment_contract_id' => $contrato->id,
                'worked_days' => 30,
                'base_salary' => 1500000,
                'salary_earned' => 1500000,
                'transport_allowance' => 0,
                'total_earnings' => 1500000,
                'total_deductions' => 120000,
                'net_pay' => 1380000,
                'employee_health' => 60000,
                'employee_pension' => 60000,
                'prefix' => 'NI',
                'consecutive' => 500,
                'cune' => 'cune-original',
                'dian_status' => PayrollSlip::DIAN_ACCEPTED,
                'dian_sent_at' => now()->subDay(),
            ]
        );

        $this->limpiar[] = function () use ($colilla, $periodo, $contrato, $empleado) {
            PayrollAdjustmentNote::withoutGlobalScopes()->where('payroll_slip_id', $colilla->id)->delete();
            DB::table('payroll_slip_lines')
                ->whereIn('payroll_slip_id', PayrollSlip::withoutGlobalScopes()
                    ->where('payroll_period_id', $periodo->id)->pluck('id'))
                ->delete();
            PayrollSlip::withoutGlobalScopes()->where('payroll_period_id', $periodo->id)->delete();
            PayrollPeriod::withoutGlobalScopes()->whereKey($periodo->id)->delete();
            EmploymentContract::withoutGlobalScopes()->whereKey($contrato->id)->delete();
            Employee::withoutGlobalScopes()->whereKey($empleado->id)->forceDelete();
        };

        return $colilla->fresh();
    }

    /** La liquidación no corre sin los parámetros del año. */
    private function parametrosDelAnio(): void
    {
        $anio = now()->subMonthNoOverflow()->year;

        if (PayrollParameter::withoutGlobalScopes()->where('year', $anio)->exists()) {
            return;
        }

        $parametro = PayrollParameter::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'year' => $anio,
            'smmlv' => 1423500,
            'transport_allowance' => 200000,
            'uvt' => 49799,
        ]);

        $this->limpiar[] = fn () => PayrollParameter::withoutGlobalScopes()->whereKey($parametro->id)->delete();
    }

    private function configDian(): void
    {
        $config = CompanyConfig::query()->firstOrNew(['company_id' => $this->companyId]);
        $original = $config->exists ? $config->replicate() : null;

        $config->fill([
            'api_url' => 'https://apidian.test',
            'api_token' => 'zz-token',
            'company_registered' => true,
            'dian_municipality_id' => Municipality::query()->value('id'),
            'payroll_test_set_id' => null,
        ])->save();

        $this->limpiar[] = function () use ($original) {
            if ($original) {
                CompanyConfig::query()
                    ->where('company_id', $this->companyId)
                    ->update(collect($original->getAttributes())
                        ->except(['id', 'created_at', 'updated_at'])
                        ->all());
            }
        };
    }
}
