<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Las variantes siguen al producto padre cuando se borra.
 *
 * Son filas de `products` con `parent_product_id`, y el listado las oculta por
 * defecto —el filtro «ver solo padres y simples» viene activo—. Borrar el padre
 * sin ellas las dejaba invisibles ahí y a la venta en el POS, que sí las
 * consulta: un cliente vació su catálogo y le siguieron apareciendo cuatro
 * tallas de una pijama, a $0, listas para facturarse.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ProductVariantDeletionTest extends TestCase
{
    private Company $company;

    /** @var list<int> */
    private array $creados = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);
    }

    protected function tearDown(): void
    {
        if ($this->creados !== []) {
            DB::table('products')->whereIn('id', $this->creados)->delete();
        }

        parent::tearDown();
    }

    /** Al borrar el padre, sus variantes dejan de existir para el POS. */
    public function test_las_variantes_se_borran_con_el_padre(): void
    {
        [$padre, $variantes] = $this->productoConVariantes();

        $padre->delete();

        $vivas = Product::withoutGlobalScopes()
            ->whereIn('id', $variantes)
            ->whereNull('deleted_at')
            ->count();

        $this->assertSame(0, $vivas,
            'Quedaron variantes vendibles de un producto que ya no existe.');
    }

    /** Y al restaurarlo, vuelven con él. */
    public function test_las_variantes_vuelven_con_el_padre(): void
    {
        [$padre, $variantes] = $this->productoConVariantes();

        $padre->delete();
        $padre->restore();

        $vivas = Product::withoutGlobalScopes()
            ->whereIn('id', $variantes)
            ->whereNull('deleted_at')
            ->count();

        $this->assertSame(count($variantes), $vivas,
            'Restaurar el padre dejaba un producto variable sin sus tallas.');
    }

    /** Borrar una variante suelta no toca a sus hermanas. */
    public function test_borrar_una_variante_no_arrastra_a_las_demas(): void
    {
        [, $variantes] = $this->productoConVariantes();

        Product::withoutGlobalScopes()->findOrFail($variantes[0])->delete();

        $vivas = Product::withoutGlobalScopes()
            ->whereIn('id', $variantes)
            ->whereNull('deleted_at')
            ->count();

        $this->assertSame(count($variantes) - 1, $vivas);
    }

    /**
     * Un producto variable con tres tallas.
     *
     * @return array{Product, list<int>}
     */
    private function productoConVariantes(): array
    {
        $sufijo = random_int(100000, 999999);

        $padre = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZVAR'.$sufijo,
            'name' => 'ZZ PIJAMA PRUEBA',
            'type' => 'variable',
            'active' => true,
            'is_sellable' => true,
        ]);

        $this->creados[] = $padre->id;

        $variantes = [];

        foreach (['S', 'M', 'L'] as $talla) {
            $variante = Product::withoutGlobalScopes()->create([
                'company_id' => $this->company->id,
                'parent_product_id' => $padre->id,
                'code' => 'ZZVAR'.$sufijo.'-'.$talla,
                'name' => 'ZZ PIJAMA PRUEBA '.$talla,
                'type' => 'good',
                'active' => true,
                'is_sellable' => true,
                'variant_attributes' => ['talla' => $talla],
            ]);

            $this->creados[] = $variante->id;
            $variantes[] = $variante->id;
        }

        return [$padre, $variantes];
    }
}
