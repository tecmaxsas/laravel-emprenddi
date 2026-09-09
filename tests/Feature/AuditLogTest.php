<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceLine;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditRegistry;
use App\Services\Auth\PermissionsCatalog;
use App\Support\CurrentCompany;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * La bitácora de auditoría.
 *
 * Antes la única huella de quién hizo qué eran las columnas `created_by_user_id`
 * regadas por veintitantas tablas: servían para saber quién creó un documento,
 * pero no quién lo modificó después, qué le cambió, quién lo borró ni quién
 * entró a la plataforma.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class AuditLogTest extends TestCase
{
    private Company $company;

    private User $user;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($this->user->company_id);
        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);

        $this->borrarBitacoraDePruebas();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        $this->borrarBitacoraDePruebas();
        app(AuditRecorder::class)->reanudar();

        parent::tearDown();
    }

    /** Crear deja constancia de quién, cuándo y sobre qué. */
    public function test_crear_un_registro_queda_en_la_bitacora(): void
    {
        $producto = $this->crearProducto();

        $entrada = $this->ultimaEntradaDe($producto);

        $this->assertNotNull($entrada, 'Crear un producto no dejó rastro.');
        $this->assertSame(AuditLog::EVENT_CREATED, $entrada->event);
        $this->assertSame($this->user->id, $entrada->user_id);
        $this->assertSame($this->company->id, $entrada->company_id);
        $this->assertStringContainsString($producto->code, (string) $entrada->auditable_label);
        $this->assertNotNull($entrada->created_at);
    }

    /** Modificar guarda el antes y el después, solo de lo que cambió. */
    public function test_modificar_guarda_el_valor_anterior_y_el_nuevo(): void
    {
        $producto = $this->crearProducto();

        $producto->update(['default_sale_price' => 250000]);

        $entrada = $this->ultimaEntradaDe($producto);

        $this->assertSame(AuditLog::EVENT_UPDATED, $entrada->event);
        $this->assertArrayHasKey('default_sale_price', $entrada->changes);
        $this->assertEqualsWithDelta(100000, (float) $entrada->changes['default_sale_price']['antes'], 0.01);
        $this->assertEqualsWithDelta(250000, (float) $entrada->changes['default_sale_price']['despues'], 0.01);

        $this->assertArrayNotHasKey('name', $entrada->changes,
            'Solo se guarda lo que cambió: copiar la fila entera no agrega respuestas.');
    }

    /** Un guardado que no cambia nada no ensucia la bitácora. */
    public function test_guardar_sin_cambios_no_registra_nada(): void
    {
        $producto = $this->crearProducto();
        $antes = $this->cuantasEntradasDe($producto);

        $producto->update(['default_sale_price' => 100000]);
        $producto->touch();

        $this->assertSame($antes, $this->cuantasEntradasDe($producto),
            'Un touch o un guardado idéntico no es una acción del usuario.');
    }

    /** Eliminar también queda, y con el nombre de lo que se eliminó. */
    public function test_eliminar_queda_registrado_con_el_nombre_del_registro(): void
    {
        $producto = $this->crearProducto();
        $codigo = $producto->code;
        $id = $producto->id;

        $producto->delete();

        $entrada = AuditLog::withoutGlobalScopes()
            ->where('auditable_type', Product::class)
            ->where('auditable_id', $id)
            ->where('event', AuditLog::EVENT_DELETED)
            ->first();

        $this->assertNotNull($entrada, 'Borrar un producto no dejó rastro.');
        $this->assertStringContainsString($codigo, (string) $entrada->auditable_label,
            'Si no se guarda el nombre, la entrada apunta a un id que ya no existe.');
    }

    /**
     * Borrar y restaurar dejan una línea cada uno. Restaurar pasa por `save()`
     * y además dispara `restored`: si se anotara en los dos sitios, un solo
     * acto quedaría dos veces.
     */
    public function test_borrar_y_restaurar_dejan_una_sola_entrada_cada_uno(): void
    {
        $producto = $this->crearProducto();

        $producto->delete();
        $producto->restore();

        $this->assertSame(1, $this->cuantasEntradasDe($producto, AuditLog::EVENT_DELETED));
        $this->assertSame(1, $this->cuantasEntradasDe($producto, AuditLog::EVENT_RESTORED));
        $this->assertSame(0, $this->cuantasEntradasDe($producto, AuditLog::EVENT_UPDATED),
            'Restaurar no es una modificación: sería una línea de más para el mismo acto.');
    }

    /** La contraseña jamás puede llegar a la bitácora. */
    public function test_los_campos_sensibles_no_se_copian(): void
    {
        $original = $this->user->name;
        $this->user->update(['name' => 'ZZ Nombre auditado', 'password' => bcrypt('ZZsecreto123')]);
        $this->limpiar[] = fn () => DB::table('users')
            ->where('id', $this->user->id)
            ->update(['name' => $original]);

        $entrada = AuditLog::withoutGlobalScopes()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $this->user->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($entrada);
        $this->assertArrayHasKey('name', $entrada->changes);
        $this->assertArrayNotHasKey('password', $entrada->changes,
            'La contraseña, aunque sea el hash, no puede quedar en una tabla que el admin lee.');

        $this->assertStringNotContainsString('password', json_encode($entrada->changes));
    }

    /** Iniciar sesión queda registrado, aunque no haya un modelo detrás. */
    public function test_el_inicio_de_sesion_queda_registrado(): void
    {
        Event::dispatch(new Login('web', $this->user, false));

        $entrada = AuditLog::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('event', AuditLog::EVENT_LOGIN)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($entrada, 'No se registró el inicio de sesión.');
        $this->assertSame($this->user->id, $entrada->user_id);
        $this->assertNull($entrada->auditable_type, 'Un login no es sobre ningún registro.');
    }

    /**
     * Un intento fallido importa más que uno exitoso: distingue un dedo torpe
     * de alguien probando claves. Se guarda el correo, nunca la contraseña.
     */
    public function test_un_intento_fallido_registra_el_correo_pero_no_la_clave(): void
    {
        Event::dispatch(new Failed('web', $this->user, [
            'email' => $this->user->email,
            'password' => 'ZZclave-que-no-debe-quedar',
        ]));

        $entrada = AuditLog::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('event', AuditLog::EVENT_LOGIN_FAILED)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($entrada, 'No se registró el intento fallido.');
        $this->assertSame($this->user->email, $entrada->changes['intento'] ?? null);
        $this->assertStringNotContainsString('ZZclave-que-no-debe-quedar', json_encode($entrada->changes));
    }

    /** Una bitácora que se puede editar o borrar no prueba nada. */
    public function test_una_entrada_no_se_puede_modificar_ni_borrar(): void
    {
        $producto = $this->crearProducto();
        $entrada = $this->ultimaEntradaDe($producto);

        try {
            $entrada->update(['event' => AuditLog::EVENT_LOGIN]);
            $this->fail('Se pudo modificar un registro de auditoría.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no se puede modificar', $e->getMessage());
        }

        try {
            $entrada->delete();
            $this->fail('Se pudo borrar un registro de auditoría.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('audit:purge', $e->getMessage());
        }
    }

    /**
     * Si la operación se revierte, no ocurrió: la bitácora no puede afirmar
     * un cambio que la base deshizo.
     */
    public function test_un_rollback_no_deja_rastro(): void
    {
        $codigo = 'ZZAUD'.random_int(10000, 99999);

        try {
            DB::transaction(function () use ($codigo) {
                Product::withoutGlobalScopes()->create([
                    'company_id' => $this->company->id,
                    'code' => $codigo,
                    'name' => 'ZZ Producto revertido',
                    'type' => 'good',
                    'unit_of_measure' => 'und',
                    'track_inventory' => false,
                    'is_sellable' => true,
                    'default_sale_price' => 1000,
                    'active' => true,
                ]);

                throw new RuntimeException('revertir');
            });
        } catch (RuntimeException) {
            // Esperado.
        }

        $this->assertSame(0, AuditLog::withoutGlobalScopes()
            ->where('auditable_label', 'like', "%{$codigo}%")->count(),
            'Un cambio que la base revirtió no puede quedar registrado como hecho.');
    }

    /**
     * Un rollback interno no puede llevarse por delante lo que la transacción
     * externa sí guardó. Este era el caso que rompía: las entradas encoladas
     * quedaban en memoria y se colaban en el siguiente commit.
     */
    public function test_un_rollback_anidado_no_arrastra_lo_de_la_transaccion_externa(): void
    {
        $externo = 'ZZAUD'.random_int(10000, 99999);
        $interno = 'ZZAUD'.random_int(10000, 99999);

        DB::transaction(function () use ($externo, $interno) {
            $bueno = $this->productoCon($externo);
            $this->limpiar[] = fn () => Product::withoutGlobalScopes()->whereKey($bueno->id)->forceDelete();

            try {
                DB::transaction(function () use ($interno) {
                    $this->productoCon($interno);

                    throw new RuntimeException('revertir solo lo de adentro');
                });
            } catch (RuntimeException) {
                // Esperado.
            }
        });

        $this->assertSame(1, AuditLog::withoutGlobalScopes()
            ->where('auditable_label', 'like', "%{$externo}%")->count(),
            'Lo que la transacción externa guardó de verdad debe quedar registrado.');

        $this->assertSame(0, AuditLog::withoutGlobalScopes()
            ->where('auditable_label', 'like', "%{$interno}%")->count(),
            'Lo revertido no puede colarse en el commit de afuera.');
    }

    /** Los modelos de mucho volumen se dejan fuera a propósito. */
    public function test_las_lineas_de_documento_no_se_auditan(): void
    {
        $this->assertFalse(AuditRegistry::audita(SaleInvoiceLine::class),
            'Auditar cada línea llenaría la bitácora de ruido y nadie la miraría.');
        $this->assertFalse(AuditRegistry::audita(InventoryMovement::class),
            'El kardex ya es su propio registro.');

        $this->assertTrue(AuditRegistry::audita(SaleInvoice::class));
        $this->assertTrue(AuditRegistry::audita(User::class));
    }

    /** La bitácora es de cada empresa: el scope de multiempresa aplica igual. */
    public function test_la_bitacora_no_cruza_empresas(): void
    {
        $this->crearProducto();

        $otra = Company::query()->where('id', '!=', $this->company->id)->first();

        if (! $otra) {
            $this->markTestSkipped('Solo hay una empresa en la base: no hay con qué cruzar.');
        }

        app(CurrentCompany::class)->set($otra);

        $this->assertSame(0, AuditLog::query()->where('company_id', $this->company->id)->count(),
            'Con otra empresa activa no debe verse ni una entrada de la primera.');
    }

    /** El permiso existe y es solo del administrador. */
    public function test_el_permiso_de_auditoria_es_solo_del_administrador(): void
    {
        $this->assertContains('audit.view', PermissionsCatalog::all());
        $this->assertContains('audit.view', PermissionsCatalog::defaultForRole('admin'));

        foreach (['manager', 'cashier', 'seller', 'accountant'] as $rol) {
            $this->assertNotContains('audit.view', PermissionsCatalog::defaultForRole($rol),
                "El rol {$rol} no debería ver los movimientos de todos los demás.");
        }
    }

    /** Se puede apagar: las migraciones de datos no deben generar bitácora. */
    public function test_la_auditoria_se_puede_pausar(): void
    {
        app(AuditRecorder::class)->pausar();

        $producto = $this->crearProducto();

        $this->assertSame(0, $this->cuantasEntradasDe($producto));

        app(AuditRecorder::class)->reanudar();
    }

    /** El nombre del usuario queda copiado, para que sobreviva a su borrado. */
    public function test_el_nombre_del_usuario_queda_copiado_en_la_entrada(): void
    {
        $tercero = ThirdParty::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'person_type' => 'natural',
            'document_type' => 'cc',
            'document_number' => 'ZZ'.random_int(100000, 999999),
            'name' => 'ZZ TERCERO AUDITADO',
            'is_customer' => true,
            'active' => true,
        ]);
        $this->limpiar[] = fn () => ThirdParty::withoutGlobalScopes()->whereKey($tercero->id)->forceDelete();

        $entrada = $this->ultimaEntradaDe($tercero);

        $this->assertSame($this->user->name, $entrada->user_name);
        $this->assertSame($this->user->email, $entrada->user_email);
        $this->assertSame($this->user->name, $entrada->actorLabel());
    }

    private function productoCon(string $codigo): Product
    {
        return Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => $codigo,
            'name' => 'ZZ Producto auditado',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 100000,
            'active' => true,
        ]);
    }

    private function crearProducto(): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZAUD'.random_int(10000, 99999),
            'name' => 'ZZ Producto auditado',
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

    private function ultimaEntradaDe(object $modelo): ?AuditLog
    {
        return AuditLog::withoutGlobalScopes()
            ->where('auditable_type', $modelo::class)
            ->where('auditable_id', $modelo->getKey())
            ->orderByDesc('id')
            ->first();
    }

    private function cuantasEntradasDe(object $modelo, ?string $evento = null): int
    {
        return AuditLog::withoutGlobalScopes()
            ->where('auditable_type', $modelo::class)
            ->where('auditable_id', $modelo->getKey())
            ->when($evento, fn ($q) => $q->where('event', $evento))
            ->count();
    }

    /** El modelo prohíbe borrar; las pruebas limpian por debajo, con SQL. */
    private function borrarBitacoraDePruebas(): void
    {
        DB::table('audit_logs')
            ->where(function ($q) {
                $q->where('auditable_label', 'like', 'ZZ%')
                    ->orWhere('auditable_label', 'like', '%ZZAUD%')
                    ->orWhere('auditable_label', 'like', '%ZZ Nombre auditado%');
            })
            ->delete();

        DB::table('audit_logs')
            ->whereIn('event', [
                AuditLog::EVENT_LOGIN,
                AuditLog::EVENT_LOGOUT,
                AuditLog::EVENT_LOGIN_FAILED,
            ])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->delete();
    }
}
