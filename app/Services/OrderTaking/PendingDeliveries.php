<?php

namespace App\Services\OrderTaking;

use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lo que se pidió y nunca salió de la bodega.
 *
 * Una sola fuente para las cuatro pantallas que lo preguntan: el informe de
 * no entregas, el consolidado mensual por cliente, y las dos exportaciones
 * (Excel y PDF). Escrita cuatro veces, la cuarta copia se queda vieja en
 * cuanto alguien cambie qué cuenta como pendiente —y entonces el Excel y la
 * pantalla dicen cifras distintas, que es peor que no tener el informe—.
 *
 * ---------------------------------------------------------------------
 * QUÉ CUENTA COMO PENDIENTE
 * ---------------------------------------------------------------------
 *
 * Una línea con `quantity_ordered` mayor que `quantity_delivered`, en un
 * pedido que NO esté anulado. Dos decisiones dentro de esa frase:
 *
 *  - **Los pedidos ANULADOS no cuentan.** Nunca se van a despachar porque
 *    la venta no existe; sumarlos inflaría el faltante con pedidos que
 *    nadie espera.
 *
 *  - **Los pedidos CERRADOS con pendientes SÍ cuentan.** Es justo el punto
 *    de poder cerrarlos: el pedido deja de estar en curso, pero lo que no
 *    se entregó sigue siendo mercancía que el cliente pidió y no recibió.
 *    Si al cerrarlos desaparecieran del informe, cerrar sería una forma de
 *    esconder el problema, y entonces nadie podría contestar cuánto se dejó
 *    de vender en el mes.
 *
 * El filtro `incluir_cerrados` permite mirarlo de las dos maneras: lo que
 * todavía se puede despachar, o todo lo que no se entregó.
 */
class PendingDeliveries
{
    /**
     * Las líneas pendientes, una por renglón de pedido.
     *
     * @param  array{from?: ?string, to?: ?string, third_party_id?: ?int,
     *               product_id?: ?int, incluir_cerrados?: bool,
     *               solo_cerrados?: bool}  $filtros
     */
    public function lineas(array $filtros = []): Builder
    {
        $desde = $filtros['from'] ?? null;
        $hasta = $filtros['to'] ?? null;

        return OrderItem::query()
            ->select('order_taking_order_items.*')
            ->join('order_taking_orders as o', 'o.id', '=', 'order_taking_order_items.order_id')
            // La comparación lleva tolerancia porque las cantidades son
            // decimales: sin ella, un redondeo de milésimas deja líneas
            // «pendientes» de 0,0001 unidades que nadie va a despachar nunca.
            ->whereRaw('order_taking_order_items.quantity_ordered - order_taking_order_items.quantity_delivered > 0.0001')
            ->where('o.status', '!=', Order::STATUS_CANCELLED)
            ->whereNull('o.deleted_at')
            ->when($desde, fn (Builder $q) => $q->whereDate('o.order_date', '>=', $desde))
            ->when($hasta, fn (Builder $q) => $q->whereDate('o.order_date', '<=', $hasta))
            ->when($filtros['third_party_id'] ?? null,
                fn (Builder $q, $v) => $q->where('o.third_party_id', $v))
            ->when($filtros['product_id'] ?? null,
                fn (Builder $q, $v) => $q->where('order_taking_order_items.product_id', $v))
            ->when(($filtros['solo_cerrados'] ?? false),
                fn (Builder $q) => $q->where('o.status', Order::STATUS_CLOSED))
            ->when(! ($filtros['incluir_cerrados'] ?? true) && ! ($filtros['solo_cerrados'] ?? false),
                fn (Builder $q) => $q->where('o.status', '!=', Order::STATUS_CLOSED))
            ->with(['order.customer:id,name,document_number', 'product:id,code,name'])
            ->orderBy('o.order_date')
            ->orderBy('o.id')
            ->orderBy('order_taking_order_items.line_number');
    }

    /**
     * Las mismas líneas, ya convertidas en filas planas.
     *
     * Lo usan las exportaciones y el PDF, que necesitan los datos resueltos
     * y no un query builder.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function filas(array $filtros = []): Collection
    {
        return $this->lineas($filtros)->get()->map(function (OrderItem $item) {
            $pedido = $item->order;
            $pendiente = $item->pendingQuantity();

            return [
                'pedido' => $pedido?->fullNumber() ?? '—',
                'order_id' => $item->order_id,
                'fecha' => $pedido?->order_date?->toDateString() ?? '',
                'dias' => $pedido?->order_date
                    ? (int) $pedido->order_date->copy()->startOfDay()->diffInDays(now()->startOfDay())
                    : 0,
                'cliente' => $pedido?->customer?->name ?? '—',
                'nit' => $pedido?->customer?->document_number ?? '',
                'codigo' => $item->product?->code ?? '',
                'referencia' => $item->description ?: ($item->product?->name ?? ''),
                'pedido_cant' => (float) $item->quantity_ordered,
                'despachado' => (float) $item->quantity_delivered,
                'pendiente' => $pendiente,
                'precio' => (float) $item->unit_price_at_public,
                'valor_pendiente' => round($pendiente * (float) $item->unit_price_at_public, 2),
                'estado' => Order::STATUSES[$pedido?->status] ?? ($pedido?->status ?? ''),
                'cerrado' => $pedido?->estaCerrado() ?? false,
                'motivo_cierre' => $pedido?->close_reason ?? '',
            ];
        });
    }

    /**
     * Consolidado del mes: cuánto le quedó pendiente a cada cliente, y de
     * qué referencias.
     *
     * Agrupa por cliente y referencia porque es como se lee la pregunta del
     * negocio: «¿a quién le quedamos mal y con qué producto?». Un mismo
     * cliente puede tener la misma referencia pendiente en tres pedidos
     * distintos del mes; aquí salen sumadas, que es lo que hay que reponer.
     *
     * @param  string  $mes  'YYYY-MM'
     * @return Collection<int, array{cliente: string, nit: string, lineas: list<array<string,mixed>>,
     *                               unidades: float, valor: float}>
     */
    public function consolidadoMensual(string $mes, array $filtros = []): Collection
    {
        [$desde, $hasta] = $this->rangoDelMes($mes);

        $filas = $this->filas(array_merge($filtros, [
            'from' => $desde,
            'to' => $hasta,
        ]));

        return $filas
            ->groupBy('cliente')
            ->map(function (Collection $delCliente, string $cliente) {
                $porReferencia = $delCliente
                    ->groupBy(fn (array $f) => $f['codigo'].'|'.$f['referencia'])
                    ->map(fn (Collection $g) => [
                        'codigo' => $g->first()['codigo'],
                        'referencia' => $g->first()['referencia'],
                        'pendiente' => round($g->sum('pendiente'), 3),
                        'valor' => round($g->sum('valor_pendiente'), 2),
                        'pedidos' => $g->pluck('pedido')->unique()->values()->all(),
                    ])
                    ->sortByDesc('valor')
                    ->values()
                    ->all();

                return [
                    'cliente' => $cliente,
                    'nit' => $delCliente->first()['nit'],
                    'lineas' => $porReferencia,
                    'unidades' => round($delCliente->sum('pendiente'), 3),
                    'valor' => round($delCliente->sum('valor_pendiente'), 2),
                ];
            })
            ->sortByDesc('valor')
            ->values();
    }

    /** @return array{0: string, 1: string} */
    public function rangoDelMes(string $mes): array
    {
        $inicio = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfMonth();

        return [$inicio->toDateString(), $inicio->copy()->endOfMonth()->toDateString()];
    }

    /**
     * El resumen de arriba del informe.
     *
     * @return array{lineas: int, unidades: float, valor: float, clientes: int}
     */
    public function resumen(array $filtros = []): array
    {
        $filas = $this->filas($filtros);

        return [
            'lineas' => $filas->count(),
            'unidades' => round($filas->sum('pendiente'), 2),
            'valor' => round($filas->sum('valor_pendiente'), 2),
            'clientes' => $filas->pluck('cliente')->unique()->count(),
        ];
    }
}
