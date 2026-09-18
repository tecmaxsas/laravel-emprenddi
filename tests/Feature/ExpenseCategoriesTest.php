<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Reports\ExpensesByCategoryPage;
use App\Filament\App\Resources\ExpenseResource\Pages\CreateExpense;
use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\User;
use App\Services\Onboarding\ExpenseCategoryProvisioner;
use App\Support\CurrentCompany;
use App\Support\DefaultAccounts;
use App\Support\ExpensesByCategory;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * En qué se le va la plata al negocio.
 *
 * La única forma de agrupar gastos era la cuenta contable del PUC, que le
 * sirve al contador pero no al dueño: «5195 - Diversos» no le dice si se le
 * fue en domicilios o en mantenimiento. Y una empresa sin el módulo de
 * contabilidad no tenía ni siquiera eso.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ExpenseCategoriesTest extends TestCase
{
    private Company $company;

    private User $user;

    private Location $sede;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($this->user->company_id);
        $this->actingAs($this->user);
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

    // ------------------------------------------------ las categorías existen

    /** La empresa arranca con categorías, no con un desplegable vacío. */
    public function test_la_empresa_ya_tiene_categorias(): void
    {
        $opciones = ExpenseCategory::opciones($this->company->id);

        $this->assertNotEmpty($opciones,
            'Un desplegable vacío obliga a interrumpir el gasto para ir a crear la primera categoría.');

        $this->assertContains('Arriendo', $opciones);
        $this->assertContains('Servicios públicos', $opciones);
    }

    /**
     * El listado inicial vive en un solo sitio.
     *
     * La migración lo siembra en las empresas que ya existían y el provisioner
     * en las nuevas. Escrito dos veces terminaría siendo dos listas distintas:
     * la empresa creada ayer tendría categorías que la de hoy no, y el reporte
     * de una no se podría comparar con el de la otra.
     */
    public function test_el_listado_inicial_no_esta_duplicado(): void
    {
        $provisioner = file_get_contents(
            app_path('Services/Onboarding/ExpenseCategoryProvisioner.php')
        );
        $migracion = file_get_contents(
            database_path('migrations/2026_09_19_090000_create_expense_categories_table.php')
        );

        foreach ([$provisioner, $migracion] as $fuente) {
            $this->assertStringContainsString('ExpenseCategory::INICIALES', $fuente,
                'Las dos tienen que leer la misma lista.');
            $this->assertStringNotContainsString("['Arriendo',", $fuente,
                'Una copia del listado es una lista que se va a quedar atrás.');
        }
    }

    /** El provisioner no duplica si vuelve a correr. */
    public function test_volver_a_provisionar_no_duplica(): void
    {
        $antes = ExpenseCategory::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->count();

        $creadas = app(ExpenseCategoryProvisioner::class)->provision($this->company);

        $despues = ExpenseCategory::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->count();

        $this->assertSame(0, $creadas);
        $this->assertSame($antes, $despues);
    }

    /** Una categoría desactivada deja de ofrecerse. */
    public function test_una_categoria_desactivada_no_se_ofrece(): void
    {
        $categoria = $this->categoria('ZZ Desactivada');
        $categoria->update(['active' => false]);

        $this->assertNotContains('ZZ Desactivada', ExpenseCategory::opciones($this->company->id));
    }

    // ------------------------------------------------ el gasto se liga

    /** Un gasto guarda su categoría. */
    public function test_el_gasto_guarda_su_categoria(): void
    {
        $categoria = $this->categoria('ZZ Domicilios');
        $gasto = $this->gasto(50000, $categoria->id);

        $this->assertSame($categoria->id, $gasto->fresh()->expense_category_id);
        $this->assertSame('ZZ Domicilios', $gasto->fresh()->category->name);
    }

    /** Se puede crear un gasto desde la pantalla eligiendo categoría. */
    public function test_se_registra_un_gasto_con_categoria_desde_la_pantalla(): void
    {
        $this->abrirCaja();
        $categoria = $this->categoria('ZZ Papelería');

        Livewire::test(CreateExpense::class)
            ->assertOk()
            ->set('data.prefix', 'ZZCAT')
            ->set('data.date', now()->toDateString())
            ->set('data.location_id', $this->sede->id)
            ->set('data.concept', 'ZZ gasto con categoria')
            ->set('data.expense_category_id', $categoria->id)
            ->set('data.subtotal', 30000)
            ->set('data.payment_method', 'cash')
            ->call('create')
            ->assertHasNoFormErrors();

        $gasto = Expense::withoutGlobalScopes()
            ->where('concept', 'ZZ gasto con categoria')->latest('id')->first();

        $this->assertNotNull($gasto);
        $this->limpiar[] = fn () => DB::table('expenses')->where('id', $gasto->id)->delete();

        $this->assertSame($categoria->id, $gasto->expense_category_id);
    }

    // ------------------------------------------------ el reporte

    /** Cada categoría suma por separado. */
    public function test_el_reporte_suma_cada_categoria(): void
    {
        $arriendo = $this->categoria('ZZ Arriendo');
        $transporte = $this->categoria('ZZ Transporte');

        $this->gasto(500000, $arriendo->id);
        $this->gasto(30000, $transporte->id);
        $this->gasto(20000, $transporte->id);

        $filas = collect(ExpensesByCategory::filas($this->filtrosDelMes()))->keyBy('categoria');

        $this->assertSame(500000.0, $filas['ZZ Arriendo']['total']);
        $this->assertSame(50000.0, $filas['ZZ Transporte']['total']);
        $this->assertSame(2, $filas['ZZ Transporte']['movimientos']);
    }

    /**
     * Lo que nadie clasificó aparece, no se esconde.
     *
     * Un reporte al que le falta plata y no lo dice es peor que uno con un
     * renglón incómodo: el total no cuadraría contra el listado de gastos y
     * nadie sabría por qué.
     */
    public function test_los_gastos_sin_categoria_aparecen(): void
    {
        $this->gasto(77000, null);

        $filas = collect(ExpensesByCategory::filas($this->filtrosDelMes()))->keyBy('categoria');

        $this->assertArrayHasKey(ExpensesByCategory::SIN_CATEGORIA, $filas->all(),
            'Esa plata se gastó: dejarla por fuera descuadra el reporte en silencio.');
        $this->assertSame(77000.0, $filas[ExpensesByCategory::SIN_CATEGORIA]['total']);
    }

    /** Se puede filtrar por una categoría. */
    public function test_se_filtra_por_categoria(): void
    {
        $arriendo = $this->categoria('ZZ Arriendo F');
        $otra = $this->categoria('ZZ Otra F');

        $this->gasto(400000, $arriendo->id);
        $this->gasto(10000, $otra->id);

        $filtros = $this->filtrosDelMes();
        $filtros['expense_category_id'] = $arriendo->id;

        $this->assertSame(400000.0, ExpensesByCategory::total($filtros));
    }

    /** Y se puede pedir expresamente lo que falta por clasificar. */
    public function test_se_filtra_lo_que_falta_por_clasificar(): void
    {
        $categoria = $this->categoria('ZZ Con categoria');

        $this->gasto(90000, null);
        $this->gasto(400000, $categoria->id);

        $filtros = $this->filtrosDelMes();
        $filtros['expense_category_id'] = 'sin_categoria';

        $this->assertSame(90000.0, ExpensesByCategory::total($filtros),
            'Sin esta opción no hay forma de ir a buscar los gastos que falta clasificar.');
    }

    /** Un gasto en borrador no es plata gastada. */
    public function test_no_cuenta_los_borradores(): void
    {
        $categoria = $this->categoria('ZZ Borrador');
        $gasto = $this->gasto(999000, $categoria->id);
        $gasto->update(['status' => Expense::STATUS_DRAFT]);

        $filas = collect(ExpensesByCategory::filas($this->filtrosDelMes()))->pluck('categoria');

        $this->assertNotContains('ZZ Borrador', $filas->all(),
            'Un borrador es una intención, no plata que salió.');
    }

    /** Un gasto anulado tampoco. */
    public function test_no_cuenta_los_anulados(): void
    {
        $categoria = $this->categoria('ZZ Anulado');
        $gasto = $this->gasto(888000, $categoria->id);
        $gasto->update(['status' => Expense::STATUS_CANCELLED]);

        $filas = collect(ExpensesByCategory::filas($this->filtrosDelMes()))->pluck('categoria');

        $this->assertNotContains('ZZ Anulado', $filas->all());
    }

    /** La pantalla abre y muestra las categorías con gasto. */
    public function test_la_pantalla_del_reporte_abre(): void
    {
        $categoria = $this->categoria('ZZ Visible');
        $this->gasto(12000, $categoria->id);

        Livewire::test(ExpensesByCategoryPage::class)
            ->assertOk()
            ->assertSee('ZZ Visible');
    }

    /** El Excel sale del mismo cálculo que la pantalla. */
    public function test_el_excel_sale_del_mismo_calculo(): void
    {
        $fuente = file_get_contents(
            app_path('Http/Controllers/App/ReportExportController.php')
        );

        $this->assertStringContainsString('ExpensesByCategory::filas($filtros)', $fuente,
            'Si el Excel arma su propia consulta, tarde o temprano dirá otro número.');
    }

    /** Y la descarga responde. */
    public function test_la_descarga_responde(): void
    {
        $categoria = $this->categoria('ZZ Descarga');
        $this->gasto(5000, $categoria->id);

        $this->get(route('reports.export.expenses_by_category', [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]))->assertOk();
    }

    // --------------------------------------------------------- auxiliares

    /** @return array<string, mixed> */
    private function filtrosDelMes(): array
    {
        return [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'location_id' => null,
            'expense_category_id' => null,
        ];
    }

    private function categoria(string $nombre): ExpenseCategory
    {
        $categoria = ExpenseCategory::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => $nombre,
            'sort_order' => 999,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => DB::table('expense_categories')->where('id', $categoria->id)->delete();

        return $categoria;
    }

    /**
     * Las dos cuentas son obligatorias en la base aunque la empresa no lleve
     * contabilidad, así que se resuelven con el mismo helper que usa la
     * pantalla en vez de inventar un id.
     */
    private function gasto(float $total, ?int $categoriaId): Expense
    {
        $gasto = Expense::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'expense_account_id' => DefaultAccounts::gasto($this->company->id),
            'payment_account_id' => $this->cuentaDeCaja(),
            'expense_category_id' => $categoriaId,
            'prefix' => 'ZZG',
            'number' => random_int(900000, 999999),
            'date' => now()->toDateString(),
            'concept' => 'ZZ gasto de prueba',
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'payment_method' => 'cash',
            'status' => Expense::STATUS_POSTED,
            'created_by_user_id' => $this->user->id,
        ]);

        $this->limpiar[] = fn () => DB::table('expenses')->where('id', $gasto->id)->delete();

        return $gasto;
    }

    private function cuentaDeCaja(): int
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('accepts_movements', true)
            ->where('code', 'like', '11%')
            ->orderBy('code')
            ->value('id');
    }

    private function abrirCaja(): void
    {
        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->user->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();
    }
}
