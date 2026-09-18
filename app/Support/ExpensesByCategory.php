<?php

namespace App\Support;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Builder;

/**
 * En qué se le fue la plata al negocio.
 *
 * La consulta vive aquí y no dentro de la pantalla porque la usan dos sitios:
 * la tabla del reporte y la exportación a Excel. Escrita dos veces, una se
 * queda atrás y el archivo empieza a decir otro número que la pantalla sin que
 * nada falle, que es lo peor que puede pasarle a una cifra.
 *
 * Dos decisiones que conviene tener presentes al leer los totales:
 *
 *  - Solo cuentan los gastos **contabilizados**. Un borrador es una intención,
 *    no plata gastada, y un gasto anulado no se gastó.
 *  - Los gastos **sin categoría** salen en su propia fila en vez de quedarse
 *    por fuera. Un reporte al que le falta plata y no lo dice es peor que uno
 *    con un renglón incómodo: así se ve cuánto falta por clasificar.
 */
class ExpensesByCategory
{
    /** El nombre que se le da a lo que nadie clasificó. */
    public const SIN_CATEGORIA = 'Sin categoría';

    /**
     * Los gastos del período, ya sumados por categoría.
     *
     * @param  array<string, mixed>  $filtros
     */
    public static function agrupado(array $filtros): Builder
    {
        return self::base($filtros)
            ->leftJoin('expense_categories as categorias', 'categorias.id', '=', 'expenses.expense_category_id')
            ->groupBy('expenses.expense_category_id', 'categorias.name')
            // `MIN(id)` no significa nada por si mismo: es la llave que Filament
            // le exige a cada fila para poder renderizar la tabla.
            ->selectRaw('MIN(expenses.id) as id')
            ->selectRaw('expenses.expense_category_id as expense_category_id')
            ->selectRaw('categorias.name as categoria')
            ->selectRaw('COUNT(*) as movimientos')
            ->selectRaw('SUM(expenses.total) as total');
    }

    /** @param  array<string, mixed>  $filtros */
    public static function total(array $filtros): float
    {
        return (float) self::base($filtros)->sum('expenses.total');
    }

    /** @param  array<string, mixed>  $filtros */
    public static function movimientos(array $filtros): int
    {
        return (int) self::base($filtros)->count();
    }

    /**
     * Lo mismo como array plano, para el Excel.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<array{categoria: string, movimientos: int, total: float, participacion: float}>
     */
    public static function filas(array $filtros): array
    {
        $total = self::total($filtros);

        return self::agrupado($filtros)
            ->orderByDesc('total')
            ->get()
            ->map(fn ($fila) => [
                'categoria' => $fila->categoria ?: self::SIN_CATEGORIA,
                'movimientos' => (int) $fila->movimientos,
                'total' => (float) $fila->total,
                'participacion' => $total > 0 ? round((float) $fila->total / $total * 100, 1) : 0.0,
            ])
            ->all();
    }

    /** @param  array<string, mixed>  $filtros */
    private static function base(array $filtros): Builder
    {
        $desde = $filtros['from'] ?? now()->startOfMonth()->toDateString();
        $hasta = $filtros['to'] ?? now()->endOfMonth()->toDateString();

        return Expense::query()
            ->where('expenses.status', Expense::STATUS_POSTED)
            ->whereDate('expenses.date', '>=', $desde)
            ->whereDate('expenses.date', '<=', $hasta)
            ->when(
                $filtros['location_id'] ?? null,
                fn (Builder $q, $sede) => $q->where('expenses.location_id', $sede),
            )
            // `sin_categoria` es un valor propio y no un id: es la unica forma
            // de pedir explicitamente «muestrame lo que falta por clasificar».
            ->when(
                ($filtros['expense_category_id'] ?? null) === 'sin_categoria',
                fn (Builder $q) => $q->whereNull('expenses.expense_category_id'),
            )
            ->when(
                ($filtros['expense_category_id'] ?? null)
                    && $filtros['expense_category_id'] !== 'sin_categoria',
                fn (Builder $q) => $q->where('expenses.expense_category_id', $filtros['expense_category_id']),
            );
    }
}
