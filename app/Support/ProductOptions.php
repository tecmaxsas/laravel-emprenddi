<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lista de productos para los selectores de las facturas.
 *
 * A diferencia de los impuestos, aqui si puede haber miles, asi que no se
 * cargan todos. Pero abrir el selector y encontrarlo vacio —«escribe para
 * buscar»— obliga a saber de memoria como se llama el producto. Se precargan
 * los primeros, que es lo que hace falta para empezar a facturar sin escribir,
 * y al teclear se busca contra la base completa por codigo, nombre o codigo de
 * barras.
 */
class ProductOptions
{
    /** Cuantos se muestran al abrir el selector sin escribir nada. */
    public const PRECARGADOS = 50;

    /** Cuantos devuelve una busqueda. */
    public const RESULTADOS = 50;

    /** @var array<string, array<int, string>> */
    protected static array $memo = [];

    /**
     * Los primeros productos, en orden alfabetico.
     *
     * @param  'sale'|'purchase'  $para
     * @return array<int, string>
     */
    public static function initial(string $para): array
    {
        $clave = (auth()->user()?->company_id ?? 0).':'.$para;

        return static::$memo[$clave] ??= static::query($para)
            ->limit(static::PRECARGADOS)
            ->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => static::label($p)])
            ->all();
    }

    /**
     * @param  'sale'|'purchase'  $para
     * @return array<int, string>
     */
    public static function search(string $para, string $termino): array
    {
        $termino = trim($termino);

        if ($termino === '') {
            return static::initial($para);
        }

        return static::query($para)
            ->where(function ($q) use ($termino) {
                $q->where('code', 'ilike', "%{$termino}%")
                    ->orWhere('name', 'ilike', "%{$termino}%")
                    ->orWhere('barcode', 'ilike', "%{$termino}%");
            })
            ->limit(static::RESULTADOS)
            ->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => static::label($p)])
            ->all();
    }

    /** La etiqueta de un producto ya guardado, aunque no este en la precarga. */
    public static function label(Product|int|string|null $producto): ?string
    {
        $producto = $producto instanceof Product ? $producto : Product::find($producto);

        return $producto ? $producto->code.' — '.$producto->name : null;
    }

    /** @return Builder<Product> */
    protected static function query(string $para)
    {
        return Product::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->where($para === 'purchase' ? 'is_purchasable' : 'is_sellable', true)
            ->where('type', '!=', 'variable')
            ->orderBy('name');
    }

    /** Para las pruebas, que crean productos despues de haber leido la lista. */
    public static function forget(): void
    {
        static::$memo = [];
    }
}
