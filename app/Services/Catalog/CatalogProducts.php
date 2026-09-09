<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Los productos que un catálogo público muestra, consultados en vivo.
 *
 * Aquí está el punto delicado de todo el módulo: **la ruta es pública, así que
 * no hay usuario y `CompanyScope` no filtra nada** —falla abierto cuando no
 * puede resolver una empresa—. Si esta consulta se escribiera como cualquier
 * otra del sistema, el catálogo de una empresa mostraría los productos de todas.
 *
 * Por eso cada consulta de aquí lleva `withoutGlobalScopes()` y un
 * `where('company_id', …)` explícito tomado del catálogo, nunca del contexto.
 */
class CatalogProducts
{
    /** Cuántos productos por página. Con más, la página pesa demasiado en celular. */
    public const POR_PAGINA = 48;

    /**
     * La página de productos del catálogo.
     *
     * @param  string|null  $busqueda  texto libre: nombre, código o marca
     * @param  int|null  $categoriaId  filtro de una categoría concreta
     */
    public function paginar(
        ProductCatalog $catalogo,
        ?string $busqueda = null,
        ?int $categoriaId = null,
    ): LengthAwarePaginator {
        return $this->base($catalogo)
            ->when($categoriaId, fn (Builder $q) => $q->where('category_id', $categoriaId))
            ->when($this->limpiar($busqueda), function (Builder $q, string $texto) {
                $q->where(function (Builder $sub) use ($texto) {
                    $sub->where('name', 'ilike', "%{$texto}%")
                        ->orWhere('code', 'ilike', "%{$texto}%")
                        ->orWhere('brand', 'ilike', "%{$texto}%")
                        ->orWhere('description', 'ilike', "%{$texto}%");
                });
            })
            ->with([
                'category:id,name',
                'variants' => fn ($q) => $q->withoutGlobalScopes()
                    ->where('active', true)
                    ->select('id', 'parent_product_id', 'name', 'variant_attributes', 'default_sale_price'),
            ])
            ->orderBy('name')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * Las categorías que de verdad tienen algo publicado.
     *
     * Se calculan de la lista real y no del catálogo de categorías: ofrecer un
     * filtro que devuelve cero resultados es peor que no ofrecerlo.
     *
     * @return Collection<int, Category>
     */
    public function categorias(ProductCatalog $catalogo): Collection
    {
        $ids = $this->base($catalogo)
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return Category::withoutGlobalScopes()
            ->where('company_id', $catalogo->company_id)
            ->whereIn('id', $ids)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function total(ProductCatalog $catalogo): int
    {
        return $this->base($catalogo)->count();
    }

    /**
     * Existencias por producto, sumando todas las sedes.
     *
     * Una sola consulta para toda la página: el saldo de cada par
     * (producto, sede) es el del último movimiento, que es exactamente lo que
     * hace InventoryEngine::currentStock() pero producto por producto. Hacerlo
     * así ahorra cientos de consultas en una página de 48 productos.
     *
     * @param  list<int>  $productIds
     * @return array<int, float>
     */
    public function existencias(ProductCatalog $catalogo, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $ultimos = DB::table('inventory_movements')
            ->selectRaw('distinct on (product_id, location_id) product_id, balance_quantity_after')
            ->where('company_id', $catalogo->company_id)
            ->whereIn('product_id', $productIds)
            ->orderByRaw('product_id, location_id, date desc, id desc');

        return DB::query()
            ->fromSub($ultimos, 'u')
            ->selectRaw('product_id, sum(balance_quantity_after) as saldo')
            ->groupBy('product_id')
            ->pluck('saldo', 'product_id')
            ->map(fn ($s) => (float) $s)
            ->all();
    }

    /**
     * La consulta base, con el aislamiento por empresa hecho a mano.
     *
     * Qué entra:
     *   - Solo productos activos de ESA empresa.
     *   - Los vendibles, más los «variables» —el producto padre de una talla o
     *     un color—, que el sistema marca como no vendibles porque lo que se
     *     vende es la variante. En un catálogo se muestra el padre.
     *   - Nunca las variantes sueltas: verían «Camiseta talla S», «talla M»
     *     y «talla L» como tres productos distintos. Van dentro del padre.
     */
    public function base(ProductCatalog $catalogo): Builder
    {
        $categorias = array_filter((array) ($catalogo->category_ids ?? []));

        return Product::withoutGlobalScopes()
            ->where('company_id', $catalogo->company_id)
            ->where('active', true)
            ->whereNull('parent_product_id')
            ->where(function (Builder $q) {
                $q->where('is_sellable', true)->orWhere('type', 'variable');
            })
            ->when($catalogo->only_with_image, fn (Builder $q) => $q
                ->whereNotNull('image_path')
                ->where('image_path', '!=', ''))
            ->when($categorias !== [], fn (Builder $q) => $q->whereIn('category_id', $categorias));
    }

    /** Un término de una o dos letras trae media base y no ayuda a nadie. */
    private function limpiar(?string $busqueda): ?string
    {
        $texto = trim((string) $busqueda);

        return mb_strlen($texto) >= 2 ? mb_substr($texto, 0, 80) : null;
    }
}
