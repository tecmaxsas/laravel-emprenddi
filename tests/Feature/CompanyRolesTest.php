<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\CompanyRoles;
use App\Support\CurrentCompany;
use App\Support\PosDestination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Los roles son de cada empresa.
 *
 * Antes `roles` no tenía company_id: las seis filas eran globales. Si una
 * empresa le quitaba un permiso al rol "cajero", se lo quitaba a los cajeros
 * de todas — así es como un parqueadero se quedó sin `parking.use` y su cajero
 * terminaba en el POS retail.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class CompanyRolesTest extends TestCase
{
    /** @var list<callable> */
    private array $limpiar = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        parent::tearDown();
    }

    /** Lo que reportó el cliente: editar un rol no puede tocar a otra empresa. */
    public function test_editar_un_rol_no_afecta_a_las_demas_empresas(): void
    {
        [$empresaA] = $this->empresaConRoles('ZZA');
        [$empresaB] = $this->empresaConRoles('ZZB');

        $cajeroA = CompanyRoles::resolve($empresaA->id, 'cashier');
        $cajeroB = CompanyRoles::resolve($empresaB->id, 'cashier');

        $this->assertNotSame($cajeroA->id, $cajeroB->id, 'Las dos empresas comparten el mismo rol.');

        $cajeroA->revokePermissionTo('pos.use');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($cajeroA->fresh()->hasPermissionTo('pos.use'));
        $this->assertTrue($cajeroB->fresh()->hasPermissionTo('pos.use'),
            'Quitarle un permiso a una empresa se lo quitó a la otra.');
    }

    /** Cada empresa arranca con su juego completo de roles. */
    public function test_una_empresa_nueva_recibe_copias_de_las_plantillas(): void
    {
        [$empresa] = $this->empresaConRoles('ZZNUEVA');

        $plantillas = CompanyRoles::templates()->pluck('name')->sort()->values();
        $suyos = Role::query()->where('company_id', $empresa->id)
            ->pluck('name')->sort()->values();

        $this->assertSame($plantillas->all(), $suyos->all());

        // Y con los permisos de la plantilla, no vacíos.
        $cajero = CompanyRoles::resolve($empresa->id, 'cashier');
        $this->assertTrue($cajero->hasPermissionTo('pos.use'));
    }

    /** Aprovisionar dos veces no duplica ni pisa personalizaciones. */
    public function test_aprovisionar_es_idempotente_y_respeta_lo_personalizado(): void
    {
        [$empresa] = $this->empresaConRoles('ZZIDEM');

        $cajero = CompanyRoles::resolve($empresa->id, 'cashier');
        $cajero->revokePermissionTo('pos.use');

        $creados = CompanyRoles::provision($empresa->fresh());

        $this->assertSame(0, $creados, 'Volvió a crear roles que ya existían.');
        $this->assertFalse($cajero->fresh()->hasPermissionTo('pos.use'),
            'El aprovisionamiento le devolvió un permiso que el administrador había quitado.');
    }

    /**
     * Asignar por nombre a secas tomaría el primer rol que coincida, que puede
     * ser de otra empresa. Por eso todo pasa por CompanyRoles.
     */
    public function test_asignar_un_rol_usa_el_de_la_empresa_del_usuario(): void
    {
        [$empresaA] = $this->empresaConRoles('ZZASIGA');
        [$empresaB, $usuarioB] = $this->empresaConRoles('ZZASIGB');

        CompanyRoles::assign($usuarioB, 'cashier');

        $rolAsignado = $usuarioB->fresh()->roles->first();

        $this->assertNotNull($rolAsignado);
        $this->assertSame($empresaB->id, (int) $rolAsignado->company_id,
            'Le asignó el rol de otra empresa.');
        $this->assertNotSame(
            CompanyRoles::resolve($empresaA->id, 'cashier')->id,
            $rolAsignado->id,
        );
    }

    /** Las plantillas no se le asignan a nadie: son solo el molde. */
    public function test_las_plantillas_no_tienen_usuarios(): void
    {
        $conUsuarios = Role::query()
            ->whereNull('company_id')
            ->has('users')
            ->pluck('name');

        $this->assertSame([], $conUsuarios->all(),
            'Hay usuarios colgando de una plantilla global: '.$conUsuarios->implode(', '));
    }

    /**
     * El cajero de un parqueadero debe ir al terminal, no al POS retail. Era
     * la consecuencia visible del rol compartido.
     */
    public function test_un_cajero_de_parqueadero_va_al_terminal(): void
    {
        [$empresa, $usuario] = $this->empresaConRoles('ZZPARK', ['parking']);
        CompanyRoles::assign($usuario, 'cashier');

        $this->actingAs($usuario->fresh());
        app(CurrentCompany::class)->set($empresa);

        $this->assertSame(PosDestination::PARKING, PosDestination::resolve());
    }

    /** Y el de un retail, al POS retail. */
    public function test_un_cajero_de_retail_va_al_pos_retail(): void
    {
        [$empresa, $usuario] = $this->empresaConRoles('ZZRETAIL', ['retail']);
        CompanyRoles::assign($usuario, 'cashier');

        $this->actingAs($usuario->fresh());
        app(CurrentCompany::class)->set($empresa);

        $this->assertSame(PosDestination::RETAIL, PosDestination::resolve());
    }

    /**
     * @param  list<string>  $modulos
     * @return array{0: Company, 1: User}
     */
    private function empresaConRoles(string $sufijo, array $modulos = ['retail']): array
    {
        $empresa = Company::create([
            'nit' => 'ZZ'.random_int(1000000, 9999999),
            'name' => 'Empresa '.$sufijo,
            'document_type' => 'nit',
            'organization_type' => 'juridica',
            'regime_type' => 'comun',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',
            'active_modules' => $modulos,
            'active' => true,
        ]);

        $usuario = User::create([
            'company_id' => $empresa->id,
            'name' => 'Usuario '.$sufijo,
            'email' => strtolower($sufijo).random_int(1000, 9999).'@zz.test',
            'password' => Hash::make('zz'),
            'active' => true,
        ]);

        CompanyRoles::provision($empresa);

        // El orden importa: los pivotes primero, luego los roles, y la empresa
        // de ultima. Al reves las llaves foraneas lo impiden y la prueba deja
        // basura en la base de desarrollo.
        $this->limpiar[] = function () use ($empresa, $usuario) {
            $roles = Role::query()->where('company_id', $empresa->id)->pluck('id');

            DB::table('model_has_roles')->whereIn('role_id', $roles)->delete();
            DB::table('role_has_permissions')->whereIn('role_id', $roles)->delete();
            DB::table('roles')->whereIn('id', $roles)->delete();

            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('model_id', $usuario->id)
                ->delete();

            DB::table('users')->where('id', $usuario->id)->delete();
            DB::table('companies')->where('id', $empresa->id)->delete();
        };

        return [$empresa, $usuario];
    }
}
