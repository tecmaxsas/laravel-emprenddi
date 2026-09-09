<?php

namespace Tests\Feature;

use App\Filament\App\Resources\AuditLogResource;
use App\Filament\App\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La pantalla de auditoría.
 *
 * Es de solo lectura por diseño: no hay crear, ni editar, ni borrar. Estas
 * pruebas cuidan justamente eso y el aislamiento por empresa, que es lo que
 * convertiría una bitácora en una fuga de datos entre clientes.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class AuditLogPageTest extends TestCase
{
    private User $admin;

    private Company $company;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->administradorConAuditoria();
        $this->company = Company::findOrFail($this->admin->company_id);

        $this->actingAs($this->admin);
        app(CurrentCompany::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        DB::table('audit_logs')->where('auditable_label', 'like', 'ZZPAG%')->delete();

        parent::tearDown();
    }

    /** El administrador abre la bitácora y ve los movimientos de su empresa. */
    public function test_el_administrador_ve_su_bitacora(): void
    {
        $producto = $this->crearProducto();

        // La tabla carga diferida (deferLoading) para no consultar una bitácora
        // grande antes de que se pinte la pantalla: en la prueba hay que pedirla.
        Livewire::test(ListAuditLogs::class)
            ->assertOk()
            ->call('loadTable')
            ->assertCanSeeTableRecords(
                AuditLog::query()
                    ->where('auditable_id', $producto->id)
                    ->where('auditable_type', Product::class)
                    ->get()
            );
    }

    /** Nadie puede crear, editar ni borrar entradas desde la pantalla. */
    public function test_la_pantalla_es_de_solo_lectura(): void
    {
        $this->assertFalse(AuditLogResource::canCreate());
        $this->assertFalse(AuditLogResource::canDeleteAny());
        $this->assertSame(['index'], array_keys(AuditLogResource::getPages()),
            'Un formulario de edición no tiene nada que hacer en una bitácora.');
    }

    /** Sin el permiso, la sección no aparece siquiera en el menú. */
    public function test_sin_permiso_no_se_ve_la_seccion(): void
    {
        // Un usuario recién creado, sin rol: es el caso que importa —el cajero
        // o el vendedor que no debe ver los movimientos de los demás—.
        $sinPermiso = User::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'ZZ Usuario sin auditoría',
            'email' => 'zz-sin-auditoria-'.random_int(10000, 99999).'@example.test',
            'password' => bcrypt('ZZclaveDePrueba123'),
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($sinPermiso) {
            DB::table('model_has_roles')->where('model_id', $sinPermiso->id)->delete();
            DB::table('audit_logs')->where('auditable_type', User::class)
                ->where('auditable_id', $sinPermiso->id)->delete();
            DB::table('users')->where('id', $sinPermiso->id)->delete();
        };

        $this->actingAs($sinPermiso);

        $this->assertFalse($sinPermiso->can('audit.view'));
        $this->assertFalse(AuditLogResource::canAccess(),
            'La bitácora muestra los movimientos de todos: no puede quedar a la vista de cualquiera.');
    }

    /** El filtro por acción es lo primero que usa quien busca algo puntual. */
    public function test_se_puede_filtrar_por_tipo_de_accion(): void
    {
        $producto = $this->crearProducto();
        $producto->update(['default_sale_price' => 333000]);

        $creacion = AuditLog::query()
            ->where('auditable_id', $producto->id)
            ->where('event', AuditLog::EVENT_CREATED)
            ->get();

        $modificacion = AuditLog::query()
            ->where('auditable_id', $producto->id)
            ->where('event', AuditLog::EVENT_UPDATED)
            ->get();

        Livewire::test(ListAuditLogs::class)
            ->call('loadTable')
            ->filterTable('event', [AuditLog::EVENT_UPDATED])
            ->assertCanSeeTableRecords($modificacion)
            ->assertCanNotSeeTableRecords($creacion);
    }

    /** Los cambios se leen como «antes → después», no como JSON crudo. */
    public function test_los_cambios_se_muestran_legibles(): void
    {
        $producto = $this->crearProducto();
        $producto->update(['default_sale_price' => 333000]);

        $entrada = AuditLog::query()
            ->where('auditable_id', $producto->id)
            ->where('event', AuditLog::EVENT_UPDATED)
            ->firstOrFail();

        $legibles = AuditLogResource::cambiosLegibles($entrada);

        $this->assertArrayHasKey('default_sale_price', $legibles);
        $this->assertStringContainsString('→', $legibles['default_sale_price']);
        $this->assertStringContainsString('333000', $legibles['default_sale_price']);
    }

    private function crearProducto(): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZPAG'.random_int(10000, 99999),
            'name' => 'ZZ Producto de pantalla',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 100000,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => Product::withoutGlobalScopes()->whereKey($producto->id)->forceDelete();

        return $producto;
    }

    /** El primer usuario de la base que de verdad tenga el permiso. */
    private function administradorConAuditoria(): User
    {
        $admin = User::withoutGlobalScopes()
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->get()
            ->first(fn (User $u) => $u->can('audit.view'));

        if (! $admin) {
            $this->markTestSkipped('Ningún usuario de la base tiene audit.view: corre las migraciones.');
        }

        return $admin;
    }
}
