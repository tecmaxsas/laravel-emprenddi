<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Services\Parking\ParkingProductProvisioner;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Borrar «Servicio de Parqueadero» no puede tumbar el cobro.
 *
 * Es un producto que el sistema crea solo y que en la lista de productos
 * parece basura, así que tarde o temprano alguien lo borra. Cuando eso pasó,
 * la factura rápida del parqueadero reventó con «Call to a member function
 * isKit() on null» en mitad de un cobro.
 *
 * Por qué: el aprovisionador busca con `withoutGlobalScopes()`, que además de
 * saltarse el filtro de empresa se salta el de borrados. Encontraba el
 * producto en la papelera y lo devolvía tan tranquilo; la factura guardaba su
 * id, y al releerla con los scopes normales el producto salía null.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ParkingProductoBorradoTest extends TestCase
{
    private Company $empresa;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Company::create([
            'nit' => 'ZZ'.random_int(1000000, 9999999),
            'name' => 'Parqueadero ZZ borrado',
            'document_type' => 'nit',
            'organization_type' => 'juridica',
            'regime_type' => 'comun',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',
            'active_modules' => ['parking'],
            'active' => true,
        ]);

        app(CurrentCompany::class)->set($this->empresa);

        $this->limpiar[] = function () {
            DB::table('products')->where('company_id', $this->empresa->id)->delete();
            DB::table('companies')->where('id', $this->empresa->id)->delete();
        };
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

    /** Si alguien lo borró, se restaura en vez de devolverlo en la papelera. */
    public function test_restaura_el_producto_borrado(): void
    {
        $provisioner = app(ParkingProductProvisioner::class);

        $original = $provisioner->ensure($this->empresa);
        $original->delete();

        $this->assertTrue($original->fresh()->trashed(), 'Preparación: tiene que quedar borrado.');

        $recuperado = $provisioner->ensure($this->empresa);

        $this->assertSame($original->id, $recuperado->id, 'Es el mismo, no uno nuevo duplicado.');
        $this->assertFalse($recuperado->trashed(), 'Tiene que volver de la papelera.');
    }

    /**
     * Y queda legible con los scopes normales.
     *
     * Es la comprobación que importa: lo que reventaba era releer el producto
     * desde la línea de la factura, donde los scopes sí aplican.
     */
    public function test_el_producto_se_puede_releer_desde_la_factura(): void
    {
        $provisioner = app(ParkingProductProvisioner::class);

        $producto = $provisioner->ensure($this->empresa);
        $producto->delete();

        $recuperado = $provisioner->ensure($this->empresa);

        $this->assertNotNull(
            Product::query()->find($recuperado->id),
            'Si no se puede leer con los scopes normales, la factura explota al contabilizar.',
        );
    }

    /** Desactivado tampoco sirve: desaparece de las búsquedas. */
    public function test_lo_reactiva_si_lo_desactivaron(): void
    {
        $provisioner = app(ParkingProductProvisioner::class);

        $producto = $provisioner->ensure($this->empresa);
        $producto->update(['active' => false, 'is_sellable' => false]);

        $recuperado = $provisioner->ensure($this->empresa);

        $this->assertTrue((bool) $recuperado->active);
        $this->assertTrue((bool) $recuperado->is_sellable);
    }
}
