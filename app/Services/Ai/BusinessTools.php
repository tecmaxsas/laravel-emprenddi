<?php

namespace App\Services\Ai;

use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lo que Claude puede consultar de la base.
 *
 * **Claude no escribe SQL.** Se le entrega un juego cerrado de consultas ya
 * escritas —ventas, inventario, cartera, gastos— y él elige cuál usar y con qué
 * fechas. Es una decisión deliberada y vale la pena explicarla, porque la
 * alternativa obvia era darle una herramienta de «ejecuta este SELECT»:
 *
 *   - Esta base es multiempresa. Un SELECT generado por un modelo que olvide un
 *     `where company_id` le muestra a un cliente los datos de otro. No es un
 *     riesgo hipotético: es el error más fácil de cometer en este esquema, y
 *     ninguna instrucción en el prompt lo previene de forma confiable.
 *   - Un usuario puede pedirle a Claude, con toda intención, que salte el
 *     filtro. Con consultas fijas no hay nada que saltar.
 *   - Una consulta mal armada sobre tablas de millones de filas tumba la base
 *     de todos los clientes.
 *
 * Con este diseño, el aislamiento no depende de que el modelo se porte bien:
 * cada consulta lleva su `company_id` cosido, tomado de la empresa en sesión y
 * nunca de lo que el modelo mande.
 *
 * Agregar una consulta nueva es añadir una entrada a `definiciones()` y su
 * método. Ninguna escribe: todas son SELECT.
 */
class BusinessTools
{
    /** Tope duro de filas que devuelve cualquier consulta. */
    private const MAX_FILAS = 200;

    public function __construct(private readonly Company $company) {}

    /**
     * El catálogo de herramientas, en el formato que espera la API de Anthropic.
     *
     * @return list<array<string, mixed>>
     */
    public function definiciones(): array
    {
        $fechas = [
            'desde' => ['type' => 'string', 'description' => 'Fecha inicial en formato AAAA-MM-DD.'],
            'hasta' => ['type' => 'string', 'description' => 'Fecha final en formato AAAA-MM-DD.'],
        ];

        return [
            $this->tool('resumen_negocio',
                'Datos generales de la empresa: nombre, sedes, y cuántos productos, clientes y '
                .'proveedores tiene. Úsala para orientarte antes de responder algo específico.'),

            $this->tool('resumen_ventas',
                'Total vendido, número de facturas, ticket promedio y desglose por medio de pago '
                .'en un rango de fechas.',
                $fechas, ['desde', 'hasta']),

            $this->tool('ventas_por_dia',
                'Ventas día por día en un rango de fechas. Úsala para ver tendencias o comparar días.',
                $fechas, ['desde', 'hasta']),

            $this->tool('ventas_por_mes',
                'Ventas mes a mes de los últimos N meses. Para tendencias largas.',
                ['meses' => ['type' => 'integer', 'description' => 'Cuántos meses hacia atrás (1 a 36).']]),

            $this->tool('productos_mas_vendidos',
                'Ranking de productos por cantidad e importe vendido en un rango de fechas.',
                $fechas + ['limite' => ['type' => 'integer', 'description' => 'Cuántos traer (por defecto 10).']],
                ['desde', 'hasta']),

            $this->tool('clientes_top',
                'Los clientes que más compraron en un rango de fechas.',
                $fechas + ['limite' => ['type' => 'integer', 'description' => 'Cuántos traer (por defecto 10).']],
                ['desde', 'hasta']),

            $this->tool('inventario',
                'Existencias actuales por producto, sumando todas las sedes. Opcionalmente filtra '
                .'por texto (nombre o código) o solo los que están por debajo del mínimo.',
                [
                    'buscar' => ['type' => 'string', 'description' => 'Texto a buscar en nombre o código.'],
                    'solo_agotados' => ['type' => 'boolean', 'description' => 'Solo los que tienen saldo cero o negativo.'],
                    'limite' => ['type' => 'integer', 'description' => 'Cuántos traer (por defecto 30).'],
                ]),

            $this->tool('cartera_clientes',
                'Clientes que deben: cuánto se les facturó, cuánto abonaron y cuánto queda pendiente. '
                .'Incluye cuántos días lleva vencida la factura más antigua.',
                ['limite' => ['type' => 'integer', 'description' => 'Cuántos traer (por defecto 20).']]),

            $this->tool('cuentas_por_pagar',
                'Proveedores a los que se les debe, con el saldo pendiente de cada uno.',
                ['limite' => ['type' => 'integer', 'description' => 'Cuántos traer (por defecto 20).']]),

            $this->tool('gastos',
                'Gastos del período agrupados por concepto, con su total.',
                $fechas, ['desde', 'hasta']),

            $this->tool('cierres_de_caja',
                'Cierres de caja del período: quién cerró, cuánto se esperaba, cuánto se contó y '
                .'la diferencia. Sirve para detectar descuadres.',
                $fechas, ['desde', 'hasta']),

            $this->tool('buscar_producto',
                'Ficha de uno o varios productos: código, nombre, categoría, precio de venta, '
                .'costo y si controla inventario.',
                ['texto' => ['type' => 'string', 'description' => 'Nombre, código o código de barras.']],
                ['texto']),

            $this->tool('buscar_tercero',
                'Ficha de un cliente o proveedor: documento, contacto, cupo de crédito y saldo.',
                ['texto' => ['type' => 'string', 'description' => 'Nombre o número de documento.']],
                ['texto']),
        ];
    }

    /**
     * Ejecuta una consulta por nombre.
     *
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    public function ejecutar(string $nombre, array $argumentos): array
    {
        $metodo = 'consulta'.str_replace(' ', '', ucwords(str_replace('_', ' ', $nombre)));

        if (! method_exists($this, $metodo)) {
            return ['error' => "No existe la consulta «{$nombre}»."];
        }

        try {
            return $this->{$metodo}($argumentos);
        } catch (InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ------------------------------------------------------------ consultas

    private function consultaResumenNegocio(array $a): array
    {
        return [
            'empresa' => $this->company->name,
            'nit' => $this->company->nit,
            'sedes' => DB::table('locations')->where('company_id', $this->id())
                ->where('active', true)->pluck('name')->all(),
            'productos_activos' => DB::table('products')->where('company_id', $this->id())
                ->where('active', true)->whereNull('deleted_at')->count(),
            'clientes' => DB::table('third_parties')->where('company_id', $this->id())
                ->where('is_customer', true)->whereNull('deleted_at')->count(),
            'proveedores' => DB::table('third_parties')->where('company_id', $this->id())
                ->where('is_supplier', true)->whereNull('deleted_at')->count(),
            'fecha_de_hoy' => now()->toDateString(),
        ];
    }

    private function consultaResumenVentas(array $a): array
    {
        [$desde, $hasta] = $this->rango($a);

        $totales = $this->ventasBase($desde, $hasta)
            ->selectRaw('count(*) as facturas, coalesce(sum(total), 0) as total')
            ->first();

        $porMedio = DB::table('payments')
            ->join('sale_invoices', function ($j) {
                $j->on('payments.paymentable_id', '=', 'sale_invoices.id')
                    ->where('payments.paymentable_type', '=', 'App\\Models\\SaleInvoice');
            })
            ->where('payments.company_id', $this->id())
            ->whereNull('payments.deleted_at')
            ->whereBetween('payments.date', [$desde, $hasta])
            ->groupBy('payments.payment_method')
            ->selectRaw('payments.payment_method, sum(payments.amount) as total')
            ->pluck('total', 'payment_method')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();

        $facturas = (int) ($totales->facturas ?? 0);
        $total = round((float) ($totales->total ?? 0), 2);

        return [
            'periodo' => "{$desde} a {$hasta}",
            'facturas' => $facturas,
            'total_vendido' => $total,
            'ticket_promedio' => $facturas > 0 ? round($total / $facturas, 2) : 0,
            'por_medio_de_pago' => $porMedio,
            'moneda' => 'COP',
        ];
    }

    private function consultaVentasPorDia(array $a): array
    {
        [$desde, $hasta] = $this->rango($a);

        return [
            'periodo' => "{$desde} a {$hasta}",
            'dias' => $this->ventasBase($desde, $hasta)
                ->groupBy('date')
                ->orderBy('date')
                ->limit(self::MAX_FILAS)
                ->selectRaw('date, count(*) as facturas, sum(total) as total')
                ->get()
                ->map(fn ($f) => [
                    'fecha' => $f->date,
                    'facturas' => (int) $f->facturas,
                    'total' => round((float) $f->total, 2),
                ])->all(),
        ];
    }

    private function consultaVentasPorMes(array $a): array
    {
        $meses = max(1, min(36, (int) ($a['meses'] ?? 12)));
        $desde = now()->subMonthsNoOverflow($meses)->startOfMonth()->toDateString();

        return [
            'desde' => $desde,
            'meses' => $this->ventasBase($desde, now()->toDateString())
                ->groupByRaw("to_char(date, 'YYYY-MM')")
                ->orderByRaw("to_char(date, 'YYYY-MM')")
                ->selectRaw("to_char(date, 'YYYY-MM') as mes, count(*) as facturas, sum(total) as total")
                ->get()
                ->map(fn ($f) => [
                    'mes' => $f->mes,
                    'facturas' => (int) $f->facturas,
                    'total' => round((float) $f->total, 2),
                ])->all(),
        ];
    }

    private function consultaProductosMasVendidos(array $a): array
    {
        [$desde, $hasta] = $this->rango($a);

        return [
            'periodo' => "{$desde} a {$hasta}",
            'productos' => DB::table('sale_invoice_lines as l')
                ->join('sale_invoices as f', 'f.id', '=', 'l.sale_invoice_id')
                ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
                ->where('f.company_id', $this->id())
                ->where('f.status', 'posted')
                ->whereNull('f.deleted_at')
                ->whereBetween('f.date', [$desde, $hasta])
                ->groupBy('p.code', 'l.description')
                ->orderByDesc(DB::raw('sum(l.total)'))
                ->limit($this->limite($a, 10))
                ->selectRaw('p.code, l.description, sum(l.quantity) as unidades, sum(l.total) as total')
                ->get()
                ->map(fn ($f) => [
                    'codigo' => $f->code,
                    'producto' => $f->description,
                    'unidades' => round((float) $f->unidades, 2),
                    'total_vendido' => round((float) $f->total, 2),
                ])->all(),
        ];
    }

    private function consultaClientesTop(array $a): array
    {
        [$desde, $hasta] = $this->rango($a);

        return [
            'periodo' => "{$desde} a {$hasta}",
            'clientes' => $this->ventasBase($desde, $hasta)
                ->leftJoin('third_parties as t', 't.id', '=', 'sale_invoices.third_party_id')
                ->groupBy('t.name', 't.document_number')
                ->orderByDesc(DB::raw('sum(sale_invoices.total)'))
                ->limit($this->limite($a, 10))
                ->selectRaw('t.name, t.document_number, count(*) as facturas, sum(sale_invoices.total) as total')
                ->get()
                ->map(fn ($f) => [
                    'cliente' => $f->name ?: 'Consumidor final',
                    'documento' => $f->document_number,
                    'facturas' => (int) $f->facturas,
                    'total_comprado' => round((float) $f->total, 2),
                ])->all(),
        ];
    }

    private function consultaInventario(array $a): array
    {
        // Saldo actual = el del último movimiento de cada par (producto, sede),
        // sumado por producto. Es lo mismo que hace InventoryEngine, en una
        // sola consulta.
        $ultimos = DB::table('inventory_movements')
            ->selectRaw('distinct on (product_id, location_id) product_id, balance_quantity_after')
            ->where('company_id', $this->id())
            ->orderByRaw('product_id, location_id, date desc, id desc');

        $saldos = DB::query()->fromSub($ultimos, 'u')
            ->selectRaw('product_id, sum(balance_quantity_after) as saldo')
            ->groupBy('product_id');

        $consulta = DB::table('products as p')
            ->leftJoinSub($saldos, 's', 's.product_id', '=', 'p.id')
            ->where('p.company_id', $this->id())
            ->where('p.active', true)
            ->where('p.track_inventory', true)
            ->whereNull('p.deleted_at');

        if ($texto = trim((string) ($a['buscar'] ?? ''))) {
            $consulta->where(function ($q) use ($texto) {
                $q->where('p.name', 'ilike', "%{$texto}%")
                    ->orWhere('p.code', 'ilike', "%{$texto}%");
            });
        }

        if ($a['solo_agotados'] ?? false) {
            $consulta->whereRaw('coalesce(s.saldo, 0) <= 0');
        }

        return [
            'productos' => $consulta
                ->orderBy('p.name')
                ->limit($this->limite($a, 30))
                ->selectRaw('p.code, p.name, coalesce(s.saldo, 0) as saldo, p.default_sale_price')
                ->get()
                ->map(fn ($f) => [
                    'codigo' => $f->code,
                    'producto' => $f->name,
                    'existencias' => round((float) $f->saldo, 2),
                    'precio_venta' => round((float) $f->default_sale_price, 2),
                ])->all(),
        ];
    }

    private function consultaCarteraClientes(array $a): array
    {
        return [
            'clientes' => DB::table('sale_invoices as f')
                ->leftJoin('third_parties as t', 't.id', '=', 'f.third_party_id')
                ->where('f.company_id', $this->id())
                ->where('f.status', 'posted')
                ->whereNull('f.deleted_at')
                ->whereRaw('coalesce(f.net_payable, f.total) - coalesce(f.paid_amount, 0) > 0.01')
                ->groupBy('t.name', 't.document_number')
                ->orderByDesc(DB::raw('sum(coalesce(f.net_payable, f.total) - coalesce(f.paid_amount, 0))'))
                ->limit($this->limite($a, 20))
                ->selectRaw('t.name, t.document_number, count(*) as facturas,
                    sum(coalesce(f.net_payable, f.total)) as facturado,
                    sum(coalesce(f.paid_amount, 0)) as abonado,
                    sum(coalesce(f.net_payable, f.total) - coalesce(f.paid_amount, 0)) as saldo,
                    min(f.date) as factura_mas_antigua')
                ->get()
                ->map(fn ($f) => [
                    'cliente' => $f->name ?: 'Sin identificar',
                    'documento' => $f->document_number,
                    'facturas_pendientes' => (int) $f->facturas,
                    'facturado' => round((float) $f->facturado, 2),
                    'abonado' => round((float) $f->abonado, 2),
                    'saldo_pendiente' => round((float) $f->saldo, 2),
                    'dias_de_la_mas_antigua' => Carbon::parse($f->factura_mas_antigua)->diffInDays(now()),
                ])->all(),
        ];
    }

    private function consultaCuentasPorPagar(array $a): array
    {
        return [
            'proveedores' => DB::table('purchase_invoices as f')
                ->leftJoin('third_parties as t', 't.id', '=', 'f.third_party_id')
                ->where('f.company_id', $this->id())
                ->where('f.status', 'posted')
                ->whereNull('f.deleted_at')
                ->whereRaw('coalesce(f.net_payable, f.total) - coalesce(f.paid_amount, 0) > 0.01')
                ->groupBy('t.name', 't.document_number')
                ->orderByDesc(DB::raw('sum(coalesce(f.net_payable, f.total) - coalesce(f.paid_amount, 0))'))
                ->limit($this->limite($a, 20))
                ->selectRaw('t.name, t.document_number, count(*) as facturas,
                    sum(coalesce(f.net_payable, f.total) - coalesce(f.paid_amount, 0)) as saldo,
                    min(f.due_date) as vencimiento_mas_proximo')
                ->get()
                ->map(fn ($f) => [
                    'proveedor' => $f->name ?: 'Sin identificar',
                    'documento' => $f->document_number,
                    'facturas_pendientes' => (int) $f->facturas,
                    'saldo_pendiente' => round((float) $f->saldo, 2),
                    'vencimiento_mas_proximo' => $f->vencimiento_mas_proximo,
                ])->all(),
        ];
    }

    private function consultaGastos(array $a): array
    {
        [$desde, $hasta] = $this->rango($a);

        return [
            'periodo' => "{$desde} a {$hasta}",
            'gastos' => DB::table('expenses')
                ->where('company_id', $this->id())
                ->where('status', 'posted')
                ->whereNull('deleted_at')
                ->whereBetween('date', [$desde, $hasta])
                ->groupBy('concept')
                ->orderByDesc(DB::raw('sum(total)'))
                ->limit(self::MAX_FILAS)
                ->selectRaw('concept, count(*) as cantidad, sum(total) as total')
                ->get()
                ->map(fn ($f) => [
                    'concepto' => $f->concept,
                    'cantidad' => (int) $f->cantidad,
                    'total' => round((float) $f->total, 2),
                ])->all(),
        ];
    }

    private function consultaCierresDeCaja(array $a): array
    {
        [$desde, $hasta] = $this->rango($a);

        return [
            'periodo' => "{$desde} a {$hasta}",
            'cierres' => DB::table('cash_register_sessions as c')
                ->leftJoin('users as u', 'u.id', '=', 'c.cashier_user_id')
                ->where('c.company_id', $this->id())
                ->whereBetween(DB::raw('c.opened_at::date'), [$desde, $hasta])
                ->orderByDesc('c.opened_at')
                ->limit(self::MAX_FILAS)
                ->selectRaw('c.opened_at, c.closed_at, c.status, u.name as cajero,
                    c.total_sales, c.invoice_count, c.closing_expected, c.closing_counted, c.closing_difference')
                ->get()
                ->map(fn ($f) => [
                    'abierta' => $f->opened_at,
                    'cerrada' => $f->closed_at,
                    'estado' => $f->status,
                    'cajero' => $f->cajero,
                    'ventas' => round((float) $f->total_sales, 2),
                    'facturas' => (int) $f->invoice_count,
                    'esperado' => round((float) $f->closing_expected, 2),
                    'contado' => round((float) $f->closing_counted, 2),
                    'diferencia' => round((float) $f->closing_difference, 2),
                ])->all(),
        ];
    }

    private function consultaBuscarProducto(array $a): array
    {
        $texto = trim((string) ($a['texto'] ?? ''));

        if (mb_strlen($texto) < 2) {
            throw new InvalidArgumentException('El texto de búsqueda debe tener al menos 2 caracteres.');
        }

        return [
            'productos' => DB::table('products as p')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->where('p.company_id', $this->id())
                ->whereNull('p.deleted_at')
                ->where(function ($q) use ($texto) {
                    $q->where('p.name', 'ilike', "%{$texto}%")
                        ->orWhere('p.code', 'ilike', "%{$texto}%")
                        ->orWhere('p.barcode', 'ilike', "%{$texto}%");
                })
                ->orderBy('p.name')
                ->limit(25)
                ->selectRaw('p.code, p.name, p.brand, c.name as categoria, p.default_sale_price,
                    p.default_purchase_price, p.track_inventory, p.active')
                ->get()
                ->map(fn ($f) => [
                    'codigo' => $f->code,
                    'producto' => $f->name,
                    'marca' => $f->brand,
                    'categoria' => $f->categoria,
                    'precio_venta' => round((float) $f->default_sale_price, 2),
                    'costo' => round((float) $f->default_purchase_price, 2),
                    'controla_inventario' => (bool) $f->track_inventory,
                    'activo' => (bool) $f->active,
                ])->all(),
        ];
    }

    private function consultaBuscarTercero(array $a): array
    {
        $texto = trim((string) ($a['texto'] ?? ''));

        if (mb_strlen($texto) < 2) {
            throw new InvalidArgumentException('El texto de búsqueda debe tener al menos 2 caracteres.');
        }

        return [
            'terceros' => DB::table('third_parties')
                ->where('company_id', $this->id())
                ->whereNull('deleted_at')
                ->where(function ($q) use ($texto) {
                    $q->where('name', 'ilike', "%{$texto}%")
                        ->orWhere('document_number', 'ilike', "%{$texto}%");
                })
                ->orderBy('name')
                ->limit(25)
                ->selectRaw('name, document_type, document_number, phone, mobile, email, city,
                    is_customer, is_supplier, credit_limit, active')
                ->get()
                ->map(fn ($f) => [
                    'nombre' => $f->name,
                    'documento' => trim(($f->document_type ?? '').' '.$f->document_number),
                    'telefono' => $f->phone ?: $f->mobile,
                    'correo' => $f->email,
                    'ciudad' => $f->city,
                    'es_cliente' => (bool) $f->is_customer,
                    'es_proveedor' => (bool) $f->is_supplier,
                    'cupo_credito' => round((float) $f->credit_limit, 2),
                    'activo' => (bool) $f->active,
                ])->all(),
        ];
    }

    // ------------------------------------------------------------- auxiliares

    /** El id de la empresa en sesión. Nunca llega desde el modelo. */
    private function id(): int
    {
        return (int) $this->company->id;
    }

    /** Facturas de venta contabilizadas de ESTA empresa, en un rango. */
    private function ventasBase(string $desde, string $hasta)
    {
        return DB::table('sale_invoices')
            ->where('company_id', $this->id())
            ->where('status', 'posted')
            ->whereNull('deleted_at')
            ->whereBetween('date', [$desde, $hasta]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function rango(array $a): array
    {
        $desde = $this->fecha($a, 'desde');
        $hasta = $this->fecha($a, 'hasta');

        if ($desde->gt($hasta)) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        return [$desde->toDateString(), $hasta->toDateString()];
    }

    /**
     * Una fecha del modelo, o un error explicando qué falta.
     *
     * Ojo con `Carbon::parse('')`: devuelve la fecha de hoy en vez de fallar. Un
     * argumento vacío daría un rango silenciosamente equivocado, que es peor que
     * un error — el modelo respondería con cifras de un período que nadie pidió.
     */
    private function fecha(array $a, string $clave): Carbon
    {
        $valor = trim((string) ($a[$clave] ?? ''));

        if ($valor === '') {
            throw new InvalidArgumentException("Falta la fecha «{$clave}» (formato AAAA-MM-DD).");
        }

        try {
            return Carbon::parse($valor);
        } catch (\Throwable) {
            throw new InvalidArgumentException(
                "La fecha «{$clave}» no se entiende: «{$valor}». Usa el formato AAAA-MM-DD."
            );
        }
    }

    private function limite(array $a, int $porDefecto): int
    {
        return max(1, min(self::MAX_FILAS, (int) ($a['limite'] ?? $porDefecto)));
    }

    /**
     * @param  array<string, array<string, mixed>>  $propiedades
     * @param  list<string>  $obligatorios
     * @return array<string, mixed>
     */
    private function tool(string $nombre, string $descripcion, array $propiedades = [], array $obligatorios = []): array
    {
        return [
            'name' => $nombre,
            'description' => $descripcion,
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) $propiedades,
                'required' => $obligatorios,
            ],
        ];
    }
}
