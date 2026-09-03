<?php

namespace Tests\Feature;

use App\Filament\App\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\User;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Alta de empleados con todo lo que la nómina electrónica necesita.
 *
 * Un empleado sin contrato no lo toma la liquidación, y sin los datos DIAN se
 * reporta con valores por defecto que para algunos trabajadores son falsos.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class EmployeePayrollSetupTest extends TestCase
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

        Filament::setCurrentPanel(Filament::getPanel('app'));
        app(CurrentCompany::class)->set($this->company);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /**
     * El contrato se captura en el mismo formulario. Antes había que guardar
     * el empleado y volver a entrar a su pestaña de contratos, y un empleado
     * sin contrato no aparece en la liquidación.
     */
    public function test_crear_un_empleado_crea_tambien_su_contrato(): void
    {
        $documento = 'ZZ'.random_int(100000, 999999);

        $this->formulario([
            'document_type' => 'cc',
            'document_number' => $documento,
            'first_name' => 'ANA',
            'last_name' => 'GOMEZ',
            'hire_date' => now()->toDateString(),
            'status' => Employee::STATUS_ACTIVE,
            'contrato.position' => 'Auxiliar contable',
            'contrato.contract_type' => 'indefinido',
            'contrato.salary_type' => 'ordinario',
            'contrato.salary' => 2500000,
            'contrato.payment_frequency' => 'mensual',
            'contrato.risk_class' => '1',
            'contrato.start_date' => now()->toDateString(),
            'contrato.transport_allowance_applies' => true,
        ])
            ->call('create')
            ->assertHasNoFormErrors();

        $empleado = Employee::query()->where('document_number', $documento)->first();
        $this->assertNotNull($empleado, 'No se creó el empleado.');

        $this->limpiar[] = function () use ($empleado) {
            EmploymentContract::query()->where('employee_id', $empleado->id)->forceDelete();
            $empleado->forceDelete();
        };

        $contrato = EmploymentContract::query()->where('employee_id', $empleado->id)->first();

        $this->assertNotNull($contrato, 'El empleado quedó sin contrato: la liquidación no lo tomaría.');
        $this->assertSame('indefinido', $contrato->contract_type);
        $this->assertSame('Auxiliar contable', $contrato->position);
        $this->assertSame(EmploymentContract::STATUS_ACTIVE, $contrato->status);
        $this->assertSame($this->companyId, (int) $contrato->company_id);
    }

    /** Los datos que la DIAN pide del trabajador se pueden capturar. */
    public function test_los_campos_dian_del_trabajador_se_guardan(): void
    {
        $documento = 'ZZ'.random_int(100000, 999999);

        $this->formulario([
            'document_type' => 'cc',
            'document_number' => $documento,
            'first_name' => 'PEDRO',
            'last_name' => 'RUIZ',
            'hire_date' => now()->toDateString(),
            'status' => Employee::STATUS_ACTIVE,
            'high_risk_pension' => true,
            'payroll_type_worker_id' => 4,
            'payroll_sub_type_worker_id' => 1,
            'contrato.position' => 'Operario',
            'contrato.contract_type' => 'fijo',
            'contrato.salary_type' => 'ordinario',
            'contrato.salary' => 1500000,
            'contrato.payment_frequency' => 'mensual',
            'contrato.start_date' => now()->toDateString(),
        ])
            ->call('create')
            ->assertHasNoFormErrors();

        $empleado = Employee::query()->where('document_number', $documento)->first();
        $this->assertNotNull($empleado);

        $this->limpiar[] = function () use ($empleado) {
            EmploymentContract::query()->where('employee_id', $empleado->id)->forceDelete();
            $empleado->forceDelete();
        };

        $this->assertTrue((bool) $empleado->high_risk_pension,
            'Sin esto la pensión de alto riesgo se reporta con el concepto equivocado.');
        $this->assertSame(4, (int) $empleado->payroll_type_worker_id);
    }

    /**
     * Llena el formulario de creación.
     *
     * Con set() y no con fillForm(): en este proyecto fillForm() deja el
     * estado intacto y la prueba falla por campos requeridos que sí se
     * llenaron, lo que despista.
     *
     * @param  array<string, mixed>  $campos
     */
    private function formulario(array $campos): Testable
    {
        $pagina = Livewire::test(CreateEmployee::class);

        foreach ($campos as $campo => $valor) {
            $pagina->set("data.{$campo}", $valor);
        }

        return $pagina;
    }
}
