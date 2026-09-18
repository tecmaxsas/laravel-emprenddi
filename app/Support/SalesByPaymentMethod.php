<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\SaleInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cuánta plata entró por cada forma de pago.
 *
 * La consulta vive aquí y no dentro de la pantalla porque la usan dos sitios:
 * la tabla del reporte y la exportación a Excel. Cuando esa clase de regla se
 * escribe dos veces, tarde o temprano una de las dos se queda atrás y el Excel
 * deja de cuadrar contra lo que el usuario ve en pantalla —sin que nada falle,
 * que es lo peor que puede pasar con un número—.
 *
 * QUÉ CUENTA, Y POR QUÉ
 *
 * Cuenta **la plata que entró**, que son dos cosas:
 *
 *   1. Los cobros aplicados a facturas de venta, por la fecha del cobro.
 *   2. Los anticipos recibidos, por su fecha y con el método con el que el
 *      cliente los entregó.
 *
 * Y excluye a propósito una tercera: **la aplicación de un anticipo a una
 * factura**. Esa operación cruza el pasivo del anticipo contra la cartera y no
 * mueve dinero; contarla sería sumar dos veces la misma plata.
 *
 * Es exactamente el mismo criterio del cierre de caja. No es un detalle: la
 * primera versión de este reporte contaba solo lo aplicado a facturas, así que
 * a un negocio que trabaja con anticipos le mostraba $2.290.200 mientras la
 * caja del mismo período decía $8.315.200. Dos pantallas del mismo sistema
 * respondiendo distinto a la misma pregunta.
 *
 * Y va por **fecha del cobro**, no de la factura: una venta a crédito entra el
 * día que el cliente paga. Por fecha de factura el total no cuadraría nunca
 * contra el arqueo ni contra el extracto del banco.
 *
 * Solo cuentan los cobros de facturas **contabilizadas**: un borrador o una
 * factura anulada no representa plata recibida.
 */
class SalesByPaymentMethod
{
    /**
     * Los movimientos del período, ya sumados por método.
     *
     * Devuelve un Builder y no una colección porque la tabla de Filament
     * necesita poder ordenarlo.
     *
     * @param  array<string, mixed>  $filtros
     */
    public static function agrupado(array $filtros): Builder
    {
        // `withoutGlobalScopes()` es obligatorio aquí y no es un descuido: la
        // consulta va contra una subconsulta con alias, y los scopes califican
        // sus columnas con el nombre real de la tabla —`payments.company_id`,
        // `payments.deleted_at`—, que en ese alias no existen. La empresa y el
        // borrado lógico se filtran dentro de cada rama de la unión.
        return Payment::query()
            ->withoutGlobalScopes()
            ->fromSub(self::union($filtros), 'movimientos')
            ->groupBy('movimientos.payment_method')
            ->selectRaw('MIN(movimientos.clave) as id')
            ->selectRaw('movimientos.payment_method as payment_method')
            ->selectRaw('COUNT(*) as operaciones')
            ->selectRaw('SUM(movimientos.amount) as total');
    }

    /** @param  array<string, mixed>  $filtros */
    public static function total(array $filtros): float
    {
        return (float) DB::query()
            ->fromSub(self::union($filtros), 'movimientos')
            ->sum('amount');
    }

    /** @param  array<string, mixed>  $filtros */
    public static function operaciones(array $filtros): int
    {
        return (int) DB::query()
            ->fromSub(self::union($filtros), 'movimientos')
            ->count();
    }

    /**
     * Lo mismo como array plano, para el Excel.
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

    /**
     * Las dos fuentes de plata, una debajo de la otra.
     *
     * @param  array<string, mixed>  $filtros
     */
    private static function union(array $filtros): QueryBuilder
    {
        return self::cobros($filtros)->unionAll(self::anticipos($filtros));
    }

    /**
     * Cobros aplicados a facturas de venta.
     *
     * @param  array<string, mixed>  $filtros
     */
    private static function cobros(array $filtros): QueryBuilder
    {
        [$desde, $hasta, $companyId] = self::parametros($filtros);

        $facturas = DB::table('sale_invoices')
            ->select('id')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', SaleInvoice::STATUS_POSTED)
            ->when(
                $filtros['location_id'] ?? null,
                fn ($q, $sede) => $q->where('location_id', $sede),
            );

        return DB::table('payments')
            // La clave no significa nada: es lo único que Filament exige para
            // poder pintar cada fila. Par para los cobros e impar para los
            // anticipos, porque los ids de las dos tablas se repiten entre sí.
            ->selectRaw('payments.id * 2 as clave')
            ->selectRaw('payments.payment_method as payment_method')
            ->selectRaw('payments.amount as amount')
            ->where('payments.company_id', $companyId)
            ->whereNull('payments.deleted_at')
            ->where('payments.paymentable_type', SaleInvoice::class)
            ->whereIn('payments.paymentable_id', $facturas)
            // La aplicación de un anticipo no mueve dinero: esa plata ya se
            // contó el día que el cliente la entregó.
            ->whereNull('payments.customer_advance_id')
            ->whereDate('payments.date', '>=', $desde)
            ->whereDate('payments.date', '<=', $hasta)
            ->when(
                $filtros['created_by_user_id'] ?? null,
                fn ($q, $usuario) => $q->where('payments.created_by_user_id', $usuario),
            );
    }

    /**
     * Anticipos recibidos.
     *
     * Es plata que entró aunque todavía no pague ninguna factura. Un anticipo
     * sin método guardado se agrupa como «Otro» en vez de suponerle uno.
     *
     * La sede sale del turno de caja en que se recibió, que es el único sitio
     * donde un anticipo la tiene. Los que no tienen turno —los registrados
     * antes de que el sistema lo guardara— quedan fuera al filtrar por sede.
     *
     * @param  array<string, mixed>  $filtros
     */
    private static function anticipos(array $filtros): QueryBuilder
    {
        [$desde, $hasta, $companyId] = self::parametros($filtros);

        $consulta = DB::table('customer_advances')
            ->selectRaw('customer_advances.id * 2 + 1 as clave')
            ->selectRaw("COALESCE(NULLIF(customer_advances.payment_method, ''), 'other') as payment_method")
            ->selectRaw('customer_advances.amount as amount')
            ->where('customer_advances.company_id', $companyId)
            ->whereDate('customer_advances.date', '>=', $desde)
            ->whereDate('customer_advances.date', '<=', $hasta)
            ->when(
                $filtros['created_by_user_id'] ?? null,
                fn ($q, $usuario) => $q->where('customer_advances.created_by_user_id', $usuario),
            );

        if ($sede = ($filtros['location_id'] ?? null)) {
            $consulta->whereIn(
                'customer_advances.cash_register_session_id',
                DB::table('cash_register_sessions')->select('id')->where('location_id', $sede),
            );
        }

        return $consulta;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array{0: string, 1: string, 2: int}
     */
    private static function parametros(array $filtros): array
    {
        return [
            $filtros['from'] ?? now()->startOfMonth()->toDateString(),
            $filtros['to'] ?? now()->endOfMonth()->toDateString(),
            (int) ($filtros['company_id'] ?? Auth::user()?->company_id),
        ];
    }
}
