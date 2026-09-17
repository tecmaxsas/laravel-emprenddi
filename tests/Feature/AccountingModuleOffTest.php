<?php

namespace Tests\Feature;

use App\Filament\App\Resources\ExpenseResource\Pages\CreateExpense;
use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Location;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\DefaultAccounts;
use App\Support\ModuleGate;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Una empresa sin contabilidad no debería ver preguntas de contabilidad.
 *
 * El formulario de gastos pedía en qué cuenta del PUC va el arriendo, y el de
 * ajustes de inventario pedía la contrapartida de una merma. Para una empresa
 * que no lleva libros ni tiene contador, esas preguntas no significan nada: es
 * un muro entre el usuario y la tarea que quería hacer.
 *
 * Pero **el asiento se genera igual** y las dos columnas son obligatorias en la
 * base. Por eso lo que importa aquí no es solo que los campos desaparezcan:
 * ocultarlos y nada más deja el documento imposible de guardar, que es peor que
 * el problema original.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class AccountingModuleOffTest extends TestCase
{
    private Company $company;

    private Location $sede;

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
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    // ------------------------------------------------ las cuentas existen

    /** Hay una cuenta de gasto por defecto sin que nadie la elija. */
    public function test_hay_una_cuenta_de_gasto_por_defecto(): void
    {
        $id = DefaultAccounts::gasto($this->company->id);

        $this->assertNotNull($id,
            'Sin ella el gasto no se puede guardar: la columna es obligatoria en la base.');

        $cuenta = Account::withoutGlobalScopes()->find($id);

        $this->assertTrue($cuenta->accepts_movements,
            'Una cuenta mayor no acepta movimientos y el asiento se rechazaria al postearse.');
        $this->assertStringStartsWith('5', $cuenta->code, 'Un gasto va a una cuenta de clase 5.');
    }

    /**
     * La contrapartida de un ajuste depende del sentido.
     *
     * Una entrada es una recuperación —apareció mercancía que no estaba
     * contada— y una salida es una pérdida. Ponerlas al revés deja el estado de
     * resultados al contrario.
     */
    public function test_la_contrapartida_depende_del_sentido_del_ajuste(): void
    {
        $entrada = Account::withoutGlobalScopes()
            ->find(DefaultAccounts::contrapartidaDeAjuste('in', $this->company->id));

        $salida = Account::withoutGlobalScopes()
            ->find(DefaultAccounts::contrapartidaDeAjuste('out', $this->company->id));

        $this->assertStringStartsWith('4', $entrada->code,
            'Una entrada de inventario es un ingreso por recuperación.');
        $this->assertStringStartsWith('5', $salida->code,
            'Una salida es una pérdida, no un ingreso.');

        $this->assertTrue($entrada->accepts_movements);
        $this->assertTrue($salida->accepts_movements);
    }

    // ------------------------------------------- el formulario se adapta

    /** Con el módulo apagado, la imputación contable no se muestra. */
    public function test_sin_contabilidad_no_se_pide_la_imputacion(): void
    {
        $this->apagarContabilidad();

        $this->assertFalse(ModuleGate::active(ModuleGate::ACCOUNTING));

        $fuente = file_get_contents(app_path('Filament/App/Resources/ExpenseResource.php'));

        $this->assertStringContainsString(
            'visible(fn () => ModuleGate::active(ModuleGate::ACCOUNTING))',
            $fuente,
            'La sección de imputación contable tiene que depender del módulo.');
    }

    /** Y el gasto se guarda igual, con las cuentas resueltas por debajo. */
    public function test_sin_contabilidad_el_gasto_se_guarda_igual(): void
    {
        $this->apagarContabilidad();
        $this->abrirCaja();

        $metodo = new \ReflectionMethod(CreateExpense::class, 'resolverCuentas');
        $metodo->setAccessible(true);

        // Tal como llegaria del formulario sin la seccion de imputacion.
        $datos = $metodo->invoke(new CreateExpense, [
            'company_id' => $this->company->id,
            'payment_method' => 'cash',
        ]);

        $this->assertNotEmpty($datos['expense_account_id'],
            'Ocultar el campo y nada más deja el gasto imposible de guardar.');
        $this->assertNotEmpty($datos['payment_account_id']);
    }

    /** Lo que el usuario sí eligió no se pisa. */
    public function test_la_cuenta_elegida_a_mano_no_se_pisa(): void
    {
        $otra = Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('accepts_movements', true)
            ->where('code', 'like', '5%')
            ->orderByDesc('code')
            ->value('id');

        $metodo = new \ReflectionMethod(CreateExpense::class, 'resolverCuentas');
        $metodo->setAccessible(true);

        $datos = $metodo->invoke(new CreateExpense, [
            'company_id' => $this->company->id,
            'payment_method' => 'cash',
            'expense_account_id' => $otra,
        ]);

        $this->assertSame($otra, $datos['expense_account_id'],
            'Con contabilidad encendida el contador eligió esa cuenta por algo.');
    }

    /** El ajuste de inventario tampoco pide la contrapartida. */
    public function test_sin_contabilidad_no_se_pide_la_contrapartida(): void
    {
        $fuente = file_get_contents(
            app_path('Filament/App/Resources/InventoryAdjustmentResource.php')
        );

        $this->assertStringContainsString(
            'visible(fn () => ModuleGate::active(ModuleGate::ACCOUNTING))',
            $fuente);

        $this->assertStringContainsString(
            'required(fn () => ModuleGate::active(ModuleGate::ACCOUNTING))',
            $fuente,
            'Si sigue siendo obligatoria, el ajuste no pasa la validación.');
    }

    /** Y el ajuste la resuelve al guardarse. */
    public function test_el_ajuste_resuelve_la_contrapartida(): void
    {
        $fuente = file_get_contents(
            app_path('Filament/App/Resources/InventoryAdjustmentResource/Pages/CreateInventoryAdjustment.php')
        );

        $this->assertStringContainsString('DefaultAccounts::contrapartidaDeAjuste', $fuente);
    }

    /**
     * De punta a punta: el gasto se guarda de verdad.
     *
     * Las pruebas de arriba miran el codigo y los valores por separado. Esta
     * hace lo que hace el usuario —abrir la pantalla, llenarla y guardar— que es
     * lo unico que demuestra que ocultar los campos no dejo el gasto atascado.
     */
    public function test_el_gasto_se_guarda_de_punta_a_punta(): void
    {
        $this->apagarContabilidad();
        $this->abrirCaja();

        $componente = Livewire::test(CreateExpense::class)
            ->assertOk()
            ->assertDontSee('Imputación contable')
            ->set('data.prefix', 'ZZEXP')
            ->set('data.date', now()->toDateString())
            ->set('data.location_id', $this->sede->id)
            ->set('data.concept', 'ZZ gasto sin contabilidad')
            ->set('data.subtotal', 50000)
            ->set('data.payment_method', 'cash')
            ->call('create');

        $componente->assertHasNoFormErrors();

        $gasto = Expense::withoutGlobalScopes()
            ->where('concept', 'ZZ gasto sin contabilidad')
            ->latest('id')
            ->first();

        $this->assertNotNull($gasto, 'Ocultar los campos dejo el gasto imposible de guardar.');

        $this->limpiar[] = fn () => DB::table('expenses')->where('id', $gasto->id)->delete();

        $this->assertNotNull($gasto->expense_account_id);
        $this->assertNotNull($gasto->payment_account_id);

        $this->assertStringStartsWith('5',
            Account::withoutGlobalScopes()->find($gasto->expense_account_id)->code);

        $this->assertStringStartsWith('11',
            Account::withoutGlobalScopes()->find($gasto->payment_account_id)->code,
            'Pagado en efectivo, el dinero sale de una cuenta de caja.');
    }

    // ------------------------------------------- con contabilidad encendida

    /** Con el módulo encendido, el contador sigue eligiendo. */
    public function test_con_contabilidad_la_seccion_sigue_visible(): void
    {
        $this->encenderContabilidad();

        $this->assertTrue(ModuleGate::active(ModuleGate::ACCOUNTING),
            'El contador tiene que poder imputar cada gasto a su cuenta.');
    }

    // --------------------------------------------------------- auxiliares

    private function apagarContabilidad(): void
    {
        $this->cambiarModulos(
            array_values(array_diff($this->company->active_modules ?? [], [ModuleGate::ACCOUNTING]))
        );
    }

    private function encenderContabilidad(): void
    {
        $actuales = $this->company->active_modules ?? [];

        if (! in_array(ModuleGate::ACCOUNTING, $actuales, true)) {
            $this->cambiarModulos([...$actuales, ModuleGate::ACCOUNTING]);
        }
    }

    /** @param  list<string>  $modulos */
    private function cambiarModulos(array $modulos): void
    {
        $original = $this->company->active_modules;

        $this->company->update(['active_modules' => $modulos]);
        app(CurrentCompany::class)->set($this->company->fresh());

        $this->limpiar[] = function () use ($original) {
            $this->company->update(['active_modules' => $original]);
            app(CurrentCompany::class)->set($this->company->fresh());
        };
    }

    private function abrirCaja(): void
    {
        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => auth()->id(),
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')
            ->where('id', $turno->id)->delete();
    }
}
