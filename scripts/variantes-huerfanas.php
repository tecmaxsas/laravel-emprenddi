<?php

/**
 * Encuentra —y si se le pide, retira— las variantes que quedaron sueltas.
 *
 * Una variante es una fila de `products` con `parent_product_id`. Hasta hoy,
 * borrar el producto padre no se las llevaba: el listado de productos las
 * oculta («ver solo padres y simples»), así que desaparecían de la vista y
 * seguían apareciendo en el POS, a la venta. Este script las busca y las borra
 * con el mismo borrado suave de la aplicación, así que se pueden restaurar.
 *
 * Uso:
 *   docker compose exec -T app php artisan tinker scripts/variantes-huerfanas.php < /dev/null
 */

use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

$DOCUMENTO = '30313628';  // documento de la empresa; null = todas
$APLICAR = false;         // true = borra; false = solo informa

$empresas = Company::withoutGlobalScopes()
    ->when($DOCUMENTO, fn ($q) => $q->where('nit', 'like', $DOCUMENTO.'%'))
    ->get(['id', 'name', 'nit']);

if ($empresas->isEmpty()) {
    echo "\n  ABORTADO: no hay empresa con documento {$DOCUMENTO}.\n\n";
    exit(1);
}

$totalHuerfanas = 0;

foreach ($empresas as $empresa) {
    // Huérfana: tiene padre declarado, y ese padre ya no existe o está borrado.
    $huerfanas = Product::withoutGlobalScopes()
        ->whereNotNull('parent_product_id')
        ->where('company_id', $empresa->id)
        ->whereNull('deleted_at')
        ->whereNotExists(fn ($q) => $q
            ->select(DB::raw(1))
            ->from('products as padres')
            ->whereColumn('padres.id', 'products.parent_product_id')
            ->whereNull('padres.deleted_at'))
        ->get(['id', 'code', 'name', 'parent_product_id', 'active', 'is_sellable', 'default_sale_price']);

    echo "\nEmpresa: {$empresa->name} (id {$empresa->id}, NIT {$empresa->nit})\n";
    echo '  Productos vivos: '.Product::withoutGlobalScopes()
        ->where('company_id', $empresa->id)->whereNull('deleted_at')->count()."\n";
    echo '  Variantes huérfanas: '.$huerfanas->count()."\n";

    foreach ($huerfanas as $v) {
        echo sprintf(
            "    id=%d · %s · %s · padre %d (ya no existe) · %s · $%s\n",
            $v->id,
            $v->code,
            mb_strimwidth($v->name, 0, 40, '…'),
            $v->parent_product_id,
            $v->is_sellable ? 'se vende' : 'no se vende',
            number_format((float) $v->default_sale_price, 2),
        );
    }

    $totalHuerfanas += $huerfanas->count();

    if ($APLICAR && $huerfanas->isNotEmpty()) {
        // Borrado suave, el mismo de la aplicación: si alguna hacía falta, se
        // puede restaurar. Un borrado definitivo aquí no se deshace.
        Product::withoutGlobalScopes()->whereIn('id', $huerfanas->pluck('id'))->delete();
        echo "  → retiradas {$huerfanas->count()} variantes.\n";
    }
}

if ($totalHuerfanas === 0) {
    echo "\n  No hay variantes huérfanas.\n\n";
    exit(0);
}

if (! $APLICAR) {
    echo "\n  Modo consulta: no se borró nada. Pon \$APLICAR = true para retirarlas.\n\n";
}

echo "\n";
