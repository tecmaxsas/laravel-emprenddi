<?php

namespace Tests\Feature;

use App\Filament\SuperAdmin\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\SuperAdmin\Resources\CompanyResource\RelationManagers\UsersRelationManager;
use App\Models\Company;
use App\Models\SaleInvoice;
use App\Models\User;
use App\Support\ResumenDeEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lo que soporte necesita ver de una empresa y de sus usuarios.
 *
 * El panel mostraba el correo pero no el nombre de usuario, y al sistema se
 * entra con cualquiera de los dos: una llamada de «no puedo entrar» se iba en
 * adivinar con qué identificador entra el cliente.
 *
 * Y sobre el reseteo de contraseña: **sí cambiaba la clave**. Lo que fallaba
 * era otra cosa —el usuario seguía sin poder entrar por una razón distinta— y
 * el login responde lo mismo en todos los casos.
 *
 * Usa la base de desarrollo y deshace lo que toca.
 */
class SuperAdminUsersTest extends TestCase
{
    private User $superAdmin;

    private Company $company;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::query()->where('is_super_admin', true)->firstOrFail();
        $this->actingAs($this->superAdmin);

        $this->company = Company::withoutGlobalScopes()->whereHas('users')->firstOrFail();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    // ------------------------------------------------- el nombre de usuario

    /**
     * El panel muestra con qué identificador entra el cliente.
     *
     * Al login se puede entrar con el correo O con el nombre de usuario. Sin
     * esta columna, soporte no sabía cuál usar.
     */
    public function test_se_ve_el_nombre_de_usuario(): void
    {
        $usuario = $this->usuario();
        $usuario->update(['username' => 'ZZPRUEBA']);

        Livewire::test(UsersRelationManager::class, [
            'ownerRecord' => $this->company,
            'pageClass' => EditCompany::class,
        ])
            ->assertOk()
            ->assertSee('ZZPRUEBA');
    }

    /** Se puede asignar desde el panel, y queda en mayúsculas. */
    public function test_se_puede_asignar_el_nombre_de_usuario(): void
    {
        $usuario = $this->usuario();

        Livewire::test(UsersRelationManager::class, [
            'ownerRecord' => $this->company,
            'pageClass' => EditCompany::class,
        ])->callTableAction('editUser', $usuario, [
            'name' => $usuario->name,
            'last_name' => $usuario->last_name,
            'email' => $usuario->email,
            'username' => 'zzminusculas',
            'active' => true,
        ])->assertHasNoTableActionErrors();

        $this->assertSame('ZZMINUSCULAS', $usuario->fresh()->username,
            'El login normaliza a mayúsculas: guardarlo distinto impediría entrar.');
    }

    /**
     * Dejarlo vacío guarda null, no cadena vacía.
     *
     * La columna es única: dos usuarios con '' chocarían entre sí.
     */
    public function test_vacio_se_guarda_como_nulo(): void
    {
        $usuario = $this->usuario();
        $usuario->update(['username' => 'ZZALGO']);

        Livewire::test(UsersRelationManager::class, [
            'ownerRecord' => $this->company,
            'pageClass' => EditCompany::class,
        ])->callTableAction('editUser', $usuario, [
            'name' => $usuario->name,
            'last_name' => $usuario->last_name,
            'email' => $usuario->email,
            'username' => '   ',
            'active' => true,
        ])->assertHasNoTableActionErrors();

        $this->assertNull($usuario->fresh()->username);
    }

    // ------------------------------------------------------- el reseteo

    /** El reseteo sí cambia la clave. */
    public function test_el_reseteo_cambia_la_clave(): void
    {
        $usuario = $this->usuario();

        Livewire::test(UsersRelationManager::class, [
            'ownerRecord' => $this->company,
            'pageClass' => EditCompany::class,
        ])->callTableAction('resetPassword', $usuario, [
            'generate_random' => false,
            'password' => 'clavedeprueba123',
        ])->assertHasNoTableActionErrors();

        $this->assertTrue(
            Hash::check('clavedeprueba123', $usuario->fresh()->getRawOriginal('password')),
            'El cast `hashed` del modelo no vuelve a hashear lo que ya viene hasheado.',
        );
    }

    /**
     * Y avisa cuando cambiarla no va a servir de nada.
     *
     * `canAccessPanel()` bloquea el login por cuatro razones y el formulario
     * responde lo mismo en todas: «credenciales incorrectas». Desde soporte
     * eso se lee como «el reseteo no funciona», y la causa real —el usuario
     * inactivo, o una suscripción vencida— no aparecía por ningún lado.
     */
    public function test_avisa_cuando_el_usuario_no_va_a_poder_entrar(): void
    {
        $usuario = $this->usuario();
        $usuario->update(['active' => false]);

        $metodo = new \ReflectionMethod(UsersRelationManager::class, 'porQueNoPodraEntrar');
        $metodo->setAccessible(true);

        $motivos = $metodo->invoke(null, $usuario->fresh());

        $this->assertNotEmpty($motivos, 'Cambiar la clave de un usuario inactivo no sirve de nada.');
        $this->assertStringContainsString('inactivo', implode(' ', $motivos));
    }

    /** Con todo en orden, no inventa advertencias. */
    public function test_sin_impedimentos_no_avisa_nada(): void
    {
        $usuario = $this->usuario();
        $usuario->update(['active' => true]);

        $metodo = new \ReflectionMethod(UsersRelationManager::class, 'porQueNoPodraEntrar');
        $metodo->setAccessible(true);

        $empresa = $usuario->company;

        if (! $empresa?->active || ! $empresa->hasActiveSubscription()) {
            $this->markTestSkipped('La empresa de desarrollo no está en condiciones de login.');
        }

        $this->assertSame([], $metodo->invoke(null, $usuario->fresh()),
            'Un aviso que sale siempre deja de leerse.');
    }

    // ------------------------------------------------------- el resumen

    /** El resumen responde las preguntas de soporte. */
    public function test_el_resumen_trae_las_cifras(): void
    {
        $r = ResumenDeEmpresa::de($this->company);

        foreach (['sedes', 'usuarios', 'facturas_pos', 'facturas_electronicas',
            'ultima_venta', 'vendido_total', 'productos', 'clientes'] as $clave) {
            $this->assertArrayHasKey($clave, $r, "Falta «{$clave}» en el resumen.");
        }

        $this->assertSame(
            DB::table('locations')->where('company_id', $this->company->id)->count(),
            $r['sedes'],
        );
    }

    /**
     * Un borrador no cuenta como factura.
     *
     * Contarlo haría parecer activa a una empresa que solo está probando, que
     * es justo lo contrario de lo que el resumen sirve para saber.
     */
    public function test_los_borradores_no_cuentan(): void
    {
        $antes = ResumenDeEmpresa::de($this->company);

        $borrador = SaleInvoice::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'location_id' => DB::table('locations')->where('company_id', $this->company->id)->value('id'),
            'third_party_id' => DB::table('third_parties')->where('company_id', $this->company->id)->value('id'),
            'prefix' => 'ZZSA', 'number' => random_int(900000, 999999),
            'date' => now()->toDateString(), 'due_date' => now()->toDateString(),
            'status' => SaleInvoice::STATUS_DRAFT,
            'invoice_kind' => 'pos',
            'subtotal' => 999999, 'tax_total' => 0, 'total' => 999999, 'net_payable' => 999999,
        ]);
        $this->limpiar[] = fn () => DB::table('sale_invoices')->where('id', $borrador->id)->delete();

        $despues = ResumenDeEmpresa::de($this->company);

        $this->assertSame($antes['facturas_pos'], $despues['facturas_pos']);
        $this->assertSame($antes['vendido_total'], $despues['vendido_total']);
    }

    /** El listado de empresas muestra cuántas sedes tiene cada una. */
    public function test_el_listado_muestra_las_sedes(): void
    {
        $fuente = file_get_contents(
            app_path('Filament/SuperAdmin/Resources/CompanyResource.php')
        );

        $this->assertStringContainsString("make('locations_count')", $fuente);
        $this->assertStringContainsString("counts('locations')", $fuente);
    }

    // --------------------------------------------------------- auxiliares

    private function usuario(): User
    {
        $usuario = User::query()->where('company_id', $this->company->id)->firstOrFail();

        $original = [
            'username' => $usuario->username,
            'active' => $usuario->active,
            'password' => $usuario->getRawOriginal('password'),
        ];

        $this->limpiar[] = fn () => DB::table('users')->where('id', $usuario->id)->update($original);

        return $usuario;
    }
}
