<?php

namespace App\Http\Controllers;

use App\Models\ProductCatalog;
use App\Services\Catalog\CatalogProducts;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * El catálogo público de productos: `/catalogo/{slug}`.
 *
 * Ruta sin autenticación. Eso trae dos consecuencias que gobiernan todo el
 * archivo:
 *
 * 1. **No hay empresa en el contexto**, así que `CompanyScope` no filtra —falla
 *    abierto—. Cada consulta lleva `withoutGlobalScopes()` y su `company_id`
 *    explícito. CatalogProducts se encarga de los productos; aquí se encarga el
 *    catálogo mismo.
 * 2. **Cualquiera puede pedirla.** Un catálogo apagado responde 404 sin decir
 *    que existe, y la ruta va con límite de peticiones.
 */
class PublicCatalogController extends Controller
{
    public function __construct(private readonly CatalogProducts $productos) {}

    public function show(Request $request, string $slug): View
    {
        $catalogo = ProductCatalog::query()
            ->withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('active', true)
            ->with('company:id,name,phone,email,address,logo_path')
            ->first();

        abort_if(! $catalogo, 404);

        $categoriaId = (int) $request->query('categoria') ?: null;
        $busqueda = $request->query('q');

        $productos = $this->productos->paginar($catalogo, $busqueda, $categoriaId);

        return view('public.catalog', [
            'catalogo' => $catalogo,
            'tema' => $catalogo->themeCompleto(),
            'productos' => $productos,
            'categorias' => $this->productos->categorias($catalogo),
            'categoriaActiva' => $categoriaId,
            'busqueda' => $busqueda,
            'existencias' => $catalogo->show_stock
                ? $this->productos->existencias($catalogo, $productos->pluck('id')->all())
                : [],
        ]);
    }
}
