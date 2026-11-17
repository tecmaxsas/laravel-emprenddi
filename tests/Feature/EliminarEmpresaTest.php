<?php

namespace Tests\Feature;

use App\Filament\SuperAdmin\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\Billiards\BilliardSession;
use App\Models\Company;
use App\Models\SaleInvoice;
use App\Models\User;
use App\Services\Billiards\BilliardAccount;
use App\Services\Billiards\BilliardBillingEngine;
use App\Services\Billiards\BilliardSessionEngine;
use App\Services\Maintenance\CompanyEraser;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\MontaUnSalonDeBillar;
use Tests\TestCase;

/**
 * Borrar una empresa entera desde el SuperAdmin.
 *
 * Es la operación más destructiva del sistema, así que lo que se prueba no
 * es solo que borre: es que borre LO JUSTO, que no deje fantasmas y que
 * cuando no puede, no borre nada.
 *
 * El caso que de verdad importa es el de una empresa CON contabilidad. Un
 * `DELETE FROM companies` a secas funciona con una empresa vacía y revienta
 * con una real:
 *
 *   ERROR: update or delete on table "accounts" violates foreign key
 *   constraint "journal_entry_lines_account_id_foreign"
 *
 * Por eso el escenario monta un salón de billar y le COBRA una mesa de
 * verdad antes de borrarlo: así hay asientos contables, renglones de
 * asiento apuntando a cuentas, factura, pago e inventario. Si el borrado
 * sobrevive a eso, sobrevive a un cliente real.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class EliminarEmpresaTest extends TestCase
{
    use MontaUnSalonDeBillar;

    private User $superAdmin;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::query()->where('is_super_admin', true)->firstOrFail();

        $this->montaUnSalonDeBillar($this->limpiar);

        $this->actingAs($this->cajero);
        app(CurrentCompany::class)->set($this->empresa);
        $this->abreUnTurnoDeCaja($this->limpiar);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        // La cola de limpieza corre igual aunque la prueba ya haya borrado
        // la empresa: un DELETE sobre filas que no están es inofensivo.
        $fallos = [];

        foreach (array_reverse($this->limpiar) as $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $fallos[] = $e->getMessage();
            }
        }

        $this->limpiar = [];

        parent::tearDown();

        $this->assertSame([], $fallos, 'La limpieza falló: '.implode(' · ', $fallos));
    }

    // ------------------------------------------------------- el borrado

    /**
     * El caso real: una empresa con contabilidad se borra entera.
     *
     * Sin el borrado por pasadas, esto falla en `accounts` porque los
     * renglones de los asientos la sujetan con un RESTRICT.
     */
    public function test_borra_una_empresa_con_contabilidad(): void
    {
        $this->cobraUnaMesa();

        $id = $this->empresa->id;

        $this->assertGreaterThan(0, DB::table('journal_entries')->where('company_id', $id)->count(),
            'El escenario tiene que tener asientos contables, o no prueba nada.');

        app(CompanyEraser::class)->borrar($this->empresa);

        $this->assertSame(0, DB::table('companies')->where('id', $id)->count(),
            'La empresa tiene que desaparecer, no quedar en la papelera.');

        foreach (['accounts', 'journal_entries', 'sale_invoices', 'products', 'locations',
            'billiard_sessions', 'billiard_session_charges', 'third_parties'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->where('company_id', $id)->count(),
                "Quedaron filas en {$tabla}.");
        }
    }

    /** Y lo que cuelga de lo borrado se va con ello. */
    public function test_no_deja_huerfanos_colgando(): void
    {
        $this->cobraUnaMesa();

        $id = $this->empresa->id;
        $facturas = DB::table('sale_invoices')->where('company_id', $id)->pluck('id');
        $asientos = DB::table('journal_entries')->where('company_id', $id)->pluck('id');

        $this->assertNotEmpty($facturas);
        $this->assertNotEmpty($asientos);

        app(CompanyEraser::class)->borrar($this->empresa);

        $this->assertSame(0, DB::table('sale_invoice_lines')->whereIn('sale_invoice_id', $facturas)->count());
        $this->assertSame(0, DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->count());
    }

    /**
     * Los usuarios se borran, no se quedan sin empresa.
     *
     * La llave foránea de `users` hacia `companies` es SET NULL: si nadie
     * los borra a mano, el borrado los deja vivos con la empresa en blanco
     * —usuarios fantasma que todavía pueden intentar entrar—.
     */
    public function test_no_deja_usuarios_fantasma(): void
    {
        $id = $this->empresa->id;
        $correo = $this->cajero->email;

        app(CompanyEraser::class)->borrar($this->empresa);

        $this->assertSame(0, User::withoutGlobalScopes()->where('email', $correo)->count(),
            'El usuario de la empresa borrada no puede quedar vivo.');

        $this->assertSame(0, User::withoutGlobalScopes()->whereNull('company_id')
            ->where('is_super_admin', false)->where('email', $correo)->count());

        $this->assertSame(0, DB::table('model_has_roles')
            ->where('model_type', User::class)->where('model_id', $this->cajero->id)->count(),
            'El pivote de roles no tiene llave foránea hacia users: hay que limpiarlo a mano.');

        $this->assertSame(0, DB::table('roles')->where('company_id', $id)->count());
    }

    /**
     * Lo más importante de todo: no toca a las demás.
     *
     * Un borrado que se lleve por delante a otra empresa no es un error, es
     * una catástrofe: son los datos contables de un cliente que paga.
     */
    public function test_no_toca_a_las_demas_empresas(): void
    {
        $otras = DB::table('companies')->where('id', '!=', $this->empresa->id)->count();
        $usuariosDeOtras = DB::table('users')->where('company_id', '!=', $this->empresa->id)->count();
        $facturasDeOtras = DB::table('sale_invoices')->where('company_id', '!=', $this->empresa->id)->count();

        $this->cobraUnaMesa();
        app(CompanyEraser::class)->borrar($this->empresa);

        $this->assertSame($otras, DB::table('companies')->count());
        $this->assertSame($usuariosDeOtras, DB::table('users')->whereNotNull('company_id')->count());
        $this->assertSame($facturasDeOtras, DB::table('sale_invoices')->count());
    }

    // ------------------------------------------------------- el resumen

    /** El resumen cuenta lo que hay, que es lo que frena la mano. */
    public function test_el_resumen_dice_que_hay_adentro(): void
    {
        $this->cobraUnaMesa();

        $grave = app(CompanyEraser::class)->loGrave($this->empresa);

        $this->assertArrayHasKey('Facturas de venta', $grave);
        $this->assertArrayHasKey('Asientos contables', $grave);
        $this->assertSame(1, $grave['Usuarios'], 'El salón de pruebas tiene un solo usuario.');
    }

    /**
     * Las tablas salen del esquema, no de una lista escrita a mano.
     *
     * Es el guarda contra la deriva que ya ocurrió: `CompanyDataReset`
     * enumera 46 tablas de las 112 que tienen `company_id` y no conoce
     * ninguna de parqueadero, hotel ni billares, porque nadie se acordó de
     * volver a tocarla al publicar un módulo. Si alguien cambia esto por
     * una lista, esta prueba lo nota.
     */
    public function test_conoce_las_tablas_de_todos_los_modulos(): void
    {
        $metodo = new \ReflectionMethod(CompanyEraser::class, 'tablasDeLaEmpresa');
        $metodo->setAccessible(true);
        $tablas = $metodo->invoke(app(CompanyEraser::class));

        foreach (['billiard_sessions', 'parking_sessions', 'hotel_stays',
            'sale_invoices', 'journal_entries', 'accounts'] as $esperada) {
            $this->assertContains($esperada, $tablas,
                "El borrado no conoce {$esperada}: una lista escrita a mano se queda vieja.");
        }

        $this->assertNotContains('users', $tablas,
            'users va aparte: su llave foránea es SET NULL, no CASCADE.');

        $this->assertNotContains('companies', $tablas);
    }

    // ------------------------------------------------- las salvaguardas

    /** Con el NIT equivocado no se borra nada. */
    public function test_el_nit_equivocado_no_borra_nada(): void
    {
        $this->actingAs($this->superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('super-admin'));

        Livewire::test(ListCompanies::class)
            ->callTableAction('eliminarEmpresa', $this->empresa, ['confirm_nit' => '000000000']);

        $this->assertSame(1, DB::table('companies')->where('id', $this->empresa->id)->count(),
            'Un NIT que no coincide no puede borrar la empresa.');
    }

    /** Con el NIT correcto, sí. */
    public function test_con_el_nit_correcto_se_borra(): void
    {
        $this->actingAs($this->superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('super-admin'));

        Livewire::test(ListCompanies::class)
            ->callTableAction('eliminarEmpresa', $this->empresa, ['confirm_nit' => $this->empresa->nit]);

        $this->assertSame(0, DB::table('companies')->where('id', $this->empresa->id)->count());
    }

    /** Nadie se borra el piso donde está parado. */
    public function test_no_se_puede_borrar_la_propia_empresa(): void
    {
        // El super-admin pasa a pertenecer a la empresa de pruebas.
        $empresaOriginal = $this->superAdmin->company_id;
        $this->superAdmin->forceFill(['company_id' => $this->empresa->id])->save();

        $this->limpiar[] = fn () => DB::table('users')->where('id', $this->superAdmin->id)
            ->update(['company_id' => $empresaOriginal]);

        $this->actingAs($this->superAdmin->fresh());
        Filament::setCurrentPanel(Filament::getPanel('super-admin'));

        Livewire::test(ListCompanies::class)
            ->callTableAction('eliminarEmpresa', $this->empresa, ['confirm_nit' => $this->empresa->nit]);

        $this->assertSame(1, DB::table('companies')->where('id', $this->empresa->id)->count(),
            'Borrar tu propia empresa te deja sin sesión a mitad de la operación.');
    }

    // --------------------------------------------------------- auxiliares

    /** Deja la empresa con factura, pago, asiento contable y renglones. */
    private function cobraUnaMesa(): void
    {
        Carbon::setTestNow('2026-11-17 14:00:00');

        $sesion = app(BilliardSessionEngine::class)->abrir([
            'table_id' => $this->mesa->id,
            'players' => 2,
            'customer_label' => 'Prueba de borrado',
        ]);

        app(BilliardAccount::class)->agregarConsumo($sesion, [
            'product_id' => $this->cerveza->id,
            'quantity' => 2,
        ]);

        Carbon::setTestNow('2026-11-17 16:00:00');

        $cerrada = app(BilliardSessionEngine::class)->cerrar($sesion->fresh(['pauses']));

        app(BilliardBillingEngine::class)->issueForSession($cerrada, [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => $this->cuentaCaja,
        ]);

        Carbon::setTestNow();

        $this->assertGreaterThan(0, SaleInvoice::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->count());
        $this->assertGreaterThan(0, BilliardSession::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->count());
    }
}
