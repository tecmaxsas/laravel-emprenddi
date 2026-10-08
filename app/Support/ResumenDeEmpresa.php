<?php

namespace App\Support;

use App\Models\Company;
use App\Models\SaleInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El pulso de una empresa, en una pantalla.
 *
 * Desde el SuperAdmin había que abrir tres sitios para responder preguntas que
 * se hacen en cada llamada de soporte: ¿cuánto factura esta empresa?, ¿sigue
 * usando el sistema?, ¿cuántas sedes tiene? Y «¿sigue activa?» no se contesta
 * con la bandera de activa: se contesta con la fecha de la última venta.
 *
 * Se separa POS de electrónica a propósito. Son dos negocios distintos: la
 * primera es un mostrador y la segunda pasa por la DIAN, cuesta dinero por
 * documento y es donde aparecen los problemas que terminan en soporte.
 *
 * Todo en una sola consulta por bloque: esto se pinta en una ficha que se abre
 * muchas veces al día.
 */
class ResumenDeEmpresa
{
    /**
     * @return array{
     *   sedes: int, usuarios: int, usuarios_activos: int,
     *   productos: int, clientes: int,
     *   facturas_pos: int, facturas_electronicas: int,
     *   vendido_total: float, vendido_mes: float,
     *   ultima_venta: ?Carbon,
     *   dias_sin_vender: ?int,
     * }
     */
    public static function de(Company $company): array
    {
        $ventas = self::ventas($company->id);

        return [
            'sedes' => DB::table('locations')->where('company_id', $company->id)->count(),
            'usuarios' => DB::table('users')->where('company_id', $company->id)->count(),
            'usuarios_activos' => DB::table('users')
                ->where('company_id', $company->id)->where('active', true)->count(),
            'productos' => DB::table('products')
                ->where('company_id', $company->id)->whereNull('deleted_at')->count(),
            'clientes' => DB::table('third_parties')
                ->where('company_id', $company->id)->whereNull('deleted_at')
                ->where('is_customer', true)->count(),

            ...$ventas,
        ];
    }

    /**
     * Las cifras de venta, en una sola consulta.
     *
     * Solo cuentan las facturas CONTABILIZADAS: un borrador no es una venta, y
     * contarlo haría parecer activa a una empresa que solo está probando.
     *
     * `invoice_kind` en null se cuenta como electrónica, igual que en el resto
     * del sistema: son las facturas anteriores a que existiera la distinción.
     *
     * @return array<string, mixed>
     */
    private static function ventas(int $companyId): array
    {
        $fila = DB::table('sale_invoices')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', SaleInvoice::STATUS_POSTED)
            ->selectRaw("COUNT(*) FILTER (WHERE invoice_kind = 'pos') as pos")
            ->selectRaw("COUNT(*) FILTER (WHERE invoice_kind IS DISTINCT FROM 'pos') as electronicas")
            ->selectRaw('COALESCE(SUM(total), 0) as vendido_total')
            ->selectRaw('COALESCE(SUM(total) FILTER (WHERE date >= ?), 0) as vendido_mes', [
                now()->startOfMonth()->toDateString(),
            ])
            ->selectRaw('MAX(date) as ultima_venta')
            ->first();

        $ultima = $fila?->ultima_venta ? Carbon::parse($fila->ultima_venta) : null;

        return [
            'facturas_pos' => (int) ($fila?->pos ?? 0),
            'facturas_electronicas' => (int) ($fila?->electronicas ?? 0),
            'vendido_total' => (float) ($fila?->vendido_total ?? 0),
            'vendido_mes' => (float) ($fila?->vendido_mes ?? 0),
            'ultima_venta' => $ultima,
            // Lo que de verdad dice si la empresa sigue viva. Una bandera de
            // «activa» la puso alguien una vez; esto lo dice la operación.
            'dias_sin_vender' => $ultima ? (int) $ultima->startOfDay()->diffInDays(now()->startOfDay()) : null,
        ];
    }
}
