<?php

namespace Tests\Feature;

use App\Filament\App\Pages\PosTerminal;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\FuerzaPosDeRetail;
use Tests\TestCase;

/**
 * Los productos favoritos en el POS.
 *
 * En un mostrador real el grueso de las ventas sale de unos veinte productos,
 * y hasta ahora el cajero los buscaba uno por uno en una grilla de cientos o
 * los tecleaba en el buscador. Cada segundo ahí es con el cliente esperando.
 *
 * Dos decisiones que estas pruebas fijan:
 *
 *  - **Favorito no es una categoría.** Un producto está en una sola categoría
 *    pero puede ser favorito además; meterlos en la misma variable obligaría
 *    a elegir entre «ver favoritos» y «ver bebidas».
 *
 *  - **Se marca desde el POS.** Nadie arma su lista editando cincuenta fichas
 *    de producto: la arma vendiendo, cuando se da cuenta de que lleva el día
 *    entero buscando el mismo artículo.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class FavoritosPosTest extends TestCase
{
    use FuerzaPosDeRetail;

    private Company $empresa;

    private User $usuario;

    private Location $sede;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($this->usuario->company_id);
        $this->actingAs($this->usuario);
        app(CurrentCompany::class)->set($this->empresa);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->orderBy('id')->firstOrFail();

        $this->fuerzaPosDeRetail($this->empresa, $this->limpiar);
        $this->turnoAbierto();
    }

    protected function tearDown(): void
    {
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

    /** Un producto nace sin ser favorito. */
    public function test_un_producto_nace_sin_ser_favorito(): void
    {
        $this->assertFalse((bool) $this->producto('ZZFAV Normal')->is_favorite);
    }

    /** El filtro deja solo los favoritos. */
    public function test_el_filtro_deja_solo_los_favoritos(): void
    {
        $normal = $this->producto('ZZFAV Normal');
        $favorito = $this->producto('ZZFAV Preferido', favorito: true);

        $componente = Livewire::test(PosTerminal::class)->call('toggleSoloFavoritos');

        $ids = $componente->instance()->products->pluck('id')->all();

        $this->assertContains($favorito->id, $ids);
        $this->assertNotContains($normal->id, $ids);
    }

    /** Y apagarlo devuelve todo el catálogo. */
    public function test_apagar_el_filtro_devuelve_todo(): void
    {
        $normal = $this->producto('ZZFAV Normal');
        $this->producto('ZZFAV Preferido', favorito: true);

        $componente = Livewire::test(PosTerminal::class)
            ->call('toggleSoloFavoritos')
            ->call('toggleSoloFavoritos');

        $this->assertContains($normal->id, $componente->instance()->products->pluck('id')->all());
    }

    /**
     * Elegir una categoría apaga el filtro de favoritos.
     *
     * Si no, el cajero vería «los favoritos de bebidas» sin haberlo pedido, y
     * una grilla casi vacía sin entender por qué.
     */
    public function test_elegir_categoria_apaga_los_favoritos(): void
    {
        $this->producto('ZZFAV Preferido', favorito: true);

        $componente = Livewire::test(PosTerminal::class)
            ->call('toggleSoloFavoritos')
            ->call('selectCategory', null);

        $this->assertFalse($componente->instance()->soloFavoritos);
    }

    /** Se marca y se desmarca desde el POS. */
    public function test_se_marca_y_desmarca_desde_el_pos(): void
    {
        $producto = $this->producto('ZZFAV Normal');

        Livewire::test(PosTerminal::class)->call('toggleFavorito', $producto->id);
        $this->assertTrue((bool) $producto->fresh()->is_favorite);

        Livewire::test(PosTerminal::class)->call('toggleFavorito', $producto->id);
        $this->assertFalse((bool) $producto->fresh()->is_favorite);
    }

    /**
     * Marcar exige permiso sobre el catálogo.
     *
     * Cambia el catálogo de la empresa para todos los cajeros, no una
     * preferencia personal de quien está en la caja.
     */
    public function test_marcar_exige_permiso_de_catalogo(): void
    {
        $producto = $this->producto('ZZFAV Normal');

        $this->actingAs($this->cajeroSinCatalogo());

        Livewire::test(PosTerminal::class)->call('toggleFavorito', $producto->id);

        $this->assertFalse((bool) $producto->fresh()->is_favorite);
    }

    /** El contador cuenta solo los vendibles y activos. */
    public function test_el_contador_solo_cuenta_los_que_se_pueden_vender(): void
    {
        $antes = Livewire::test(PosTerminal::class)->instance()->cuantosFavoritos;

        $this->producto('ZZFAV Preferido', favorito: true);
        $this->producto('ZZFAV Apagado', favorito: true, activo: false);

        $this->assertSame($antes + 1, Livewire::test(PosTerminal::class)->instance()->cuantosFavoritos,
            'Un favorito inactivo no se puede vender: contarlo prometería algo que no está en la grilla.');
    }

    // --------------------------------------------------------- auxiliares

    private function producto(string $nombre, bool $favorito = false, bool $activo = true): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZFAV'.random_int(10000, 99999),
            'name' => $nombre,
            'type' => 'service',
            'unit_of_measure' => 'service',
            'track_inventory' => false,
            'is_sellable' => true,
            'is_favorite' => $favorito,
            'default_sale_price' => 10000,
            'active' => $activo,
        ]);

        $this->limpiar[] = fn () => Product::withoutGlobalScopes()
            ->whereKey($producto->id)->forceDelete();

        return $producto;
    }

    /** Un cajero que usa el POS pero no administra el catálogo. */
    private function cajeroSinCatalogo(): User
    {
        $rol = Role::query()
            ->where('company_id', $this->empresa->id)
            ->where('name', 'CAJERO')
            ->first();

        $this->assertNotNull($rol, 'La empresa de pruebas necesita un rol CAJERO propio.');

        $cajero = User::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'name' => 'ZZFAV Cajero',
            'email' => 'zzfav'.random_int(100000, 999999).'@pruebas.local',
            'password' => 'no-se-usa-'.random_int(1000, 9999),
            'active' => true,
        ]);

        $this->limpiar[] = function () use ($cajero) {
            DB::table('model_has_roles')->where('model_id', $cajero->id)
                ->where('model_type', User::class)->delete();
            DB::table('users')->where('id', $cajero->id)->delete();
        };

        $cajero->assignRole($rol);

        return $cajero->fresh();
    }

    private function turnoAbierto(): void
    {
        $previos = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->pluck('id');

        if ($previos->isNotEmpty()) {
            DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_CLOSED, 'closed_at' => now()]);

            $this->limpiar[] = fn () => DB::table('cash_register_sessions')->whereIn('id', $previos)
                ->update(['status' => CashRegisterSession::STATUS_OPEN, 'closed_at' => null]);
        }

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->usuario->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $this->limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();
    }
}
