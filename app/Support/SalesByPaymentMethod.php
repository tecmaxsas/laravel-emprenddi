<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\SaleInvoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cuánta plata entró por cada forma de pago.
 *
 * La consulta vive aquí y no dentro de la pantalla porque la usan dos sitios:
 * la tabla del reporte y la exportación a Excel. Cuando esa clase de regla se
 * escribe dos veces, tarde o temprano una de las dos se queda atrás y el Excel
 * deja de cuadrar contra lo que el usuario ve en pantalla —sin que nada falle,
 * que es lo peor que puede pasar con un número—.
 *
 * Dos decisiones que conviene tener presentes al leer los totales:
 *
 *  - Se cuenta por **fecha del pago**, no por la de la factura. Una venta a
 *    crédito entra el día que el cliente paga, que es cuando la plata existe.
 *    Por fecha de factura el reporte no cuadraría nunca contra el arqueo.
 *  - Solo cuentan los pagos de facturas de venta **contabilizadas**. Un
 *    borrador o una factura anulada no representa plata recibida.
 */
class SalesByPaymentMethod
{
    /**
     * Los pagos del período, ya sumados por método.
     *
     * Devuelve un Builder y no una colección porque la tabla de Filament
     * necesita poder ordenarlo y paginarlo.
     *
     * @param  array<string, mixed>  $filtros
     */
    public static function agrupado(array $filtros): Builder
    {
        return self::base($filtros)
            ->groupBy('payments.payment_method')
            // `MIN(id)` no significa nada por si mismo: es la llave que Filament
            // le exige a cada fila para poder renderizar la tabla.
            ->selectRaw('MIN(payments.id) as id')
            ->selectRaw('payments.payment_method as payment_method')
            ->selectRaw('COUNT(*) as operaciones')
            ->selectRaw('SUM(payments.amount) as total');
    }

    /** @param  array<string, mixed>  $filtros */
    public static function total(array $filtros): float
    {
        return (float) self::base($filtros)->sum('payments.amount');
    }

    /** @param  array<string, mixed>  $filtros */
    public static function operaciones(array $filtros): int
    {
        return (int) self::base($filtros)->count();
    }

    /**
     * Lo mismo pero como array plano, para el Excel.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<array{metodo: string, codigo: string, operaciones: int, total: float, participacion: float}>
     */
    public static function filas(array $filtros): array
    {
        $total = self::total($filtros);

        return self::agrupado($filtros)
            ->orderByDesc('total')
            ->get()
            ->map(fn ($fila) => [
                'metodo' => PaymentMethodOptions::nombre($fila->payment_method),
                'codigo' => (string) $fila->payment_method,
                'operaciones' => (int) $fila->operaciones,
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

        // Se filtra por subconsulta y no por join: `SaleInvoice` trae su propio
        // scope de empresa, asi que la sede se restringe sin arrastrar las
        // columnas de la factura ni arriesgar un `company_id` ambiguo.
        $facturas = SaleInvoice::query()
            ->where('status', SaleInvoice::STATUS_POSTED)
            ->when(
                $filtros['location_id'] ?? null,
                fn (Builder $q, $sede) => $q->where('location_id', $sede),
            )
            ->select('id');

        return Payment::query()
            ->where('paymentable_type', SaleInvoice::class)
            ->whereIn('paymentable_id', $facturas)
            ->whereDate('payments.date', '>=', $desde)
            ->whereDate('payments.date', '<=', $hasta)
            ->when(
                $filtros['created_by_user_id'] ?? null,
                fn (Builder $q, $usuario) => $q->where('created_by_user_id', $usuario),
            );
    }
}
