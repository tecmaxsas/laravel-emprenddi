<?php

namespace App\Services\Cash;

use App\Models\CashRegisterSession;
use App\Models\CustomerAdvance;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;

/**
 * Resumen en vivo de una sesión de caja: ingresos (pagos de ventas
 * recibidos en la sesión), egresos (pagos de compras + gastos hechos
 * en la sesión), desglose por método y saldo esperado en efectivo.
 *
 * Solo el efectivo afecta el "saldo esperado" — pagos por transferencia,
 * tarjeta, etc., aparecen en el desglose informativo pero NO bajan ni
 * suben la caja física. Decisión de producto confirmada con el cliente:
 * el cajero responde solo por el efectivo en el cajón.
 */
class CashSessionSummary
{
    /**
     * Computa todos los totales de una sesión. Caché por proceso para que
     * el modal "Detalles caja" y el modal "Cerrar caja" no repitan queries.
     *
     * @return array{
     *   sales: array{count:int, total:float, by_method:array<string,float>, cash:float},
     *   purchases: array{count:int, total:float, by_method:array<string,float>, cash:float},
     *   expenses: array{count:int, total:float, by_method:array<string,float>, cash:float},
     *   net_cash: float,
     *   expected_cash: float,
     *   payment_breakdown: array<string,float>,
     * }
     */
    public function compute(CashRegisterSession $session): array
    {
        $sales = $this->summarizeSalePayments($session);
        $purchases = $this->summarizePurchasePayments($session);
        $expenses = $this->summarizeExpenses($session);

        $netCash = $sales['cash'] - $purchases['cash'] - $expenses['cash'];
        $expectedCash = (float) $session->opening_amount + $netCash;

        // Breakdown global por método: suma ventas (positivo) menos compras
        // y gastos (negativo) — útil para auditar todos los movimientos de la
        // sesión en una sola tabla.
        $breakdown = [];
        foreach ($sales['by_method'] as $method => $amount) {
            $breakdown[$method] = ($breakdown[$method] ?? 0) + $amount;
        }
        foreach ($purchases['by_method'] as $method => $amount) {
            $breakdown[$method] = ($breakdown[$method] ?? 0) - $amount;
        }
        foreach ($expenses['by_method'] as $method => $amount) {
            $breakdown[$method] = ($breakdown[$method] ?? 0) - $amount;
        }

        return [
            'sales' => $sales,
            'purchases' => $purchases,
            'expenses' => $expenses,
            'net_cash' => round($netCash, 2),
            'expected_cash' => round($expectedCash, 2),
            'payment_breakdown' => $breakdown,
        ];
    }

    /**
     * Plata RECIBIDA en esta sesión. Agrupa por método; el efectivo entra a
     * la caja física.
     *
     * Son dos cosas que se suman:
     *
     *  1. Los pagos de facturas de venta hechos en la sesión.
     *  2. Los anticipos recibidos en la sesión, con el método con el que el
     *     cliente entregó esa plata.
     *
     * Y se excluye a propósito una tercera: **la aplicación de un anticipo a
     * una factura**. Esa operación no mueve dinero —cruza el pasivo del
     * anticipo contra la cartera—, así que contarla sería contar dos veces la
     * misma plata: una cuando entró y otra cuando pagó la factura.
     *
     * Antes esas aplicaciones aparecían como «Anticipo del cliente» y se
     * comían el desglose entero, mientras el efectivo real del anticipo no
     * entraba en «Esperado en caja» por ningún lado.
     */
    protected function summarizeSalePayments(CashRegisterSession $session): array
    {
        $payments = Payment::query()
            ->where('cash_register_session_id', $session->id)
            ->where('paymentable_type', SaleInvoice::class)
            ->whereNull('customer_advance_id')
            ->get(['amount', 'payment_method', 'paymentable_id']);

        $resumen = $this->groupPayments($payments);

        return $this->sumarAnticipos($resumen, $session);
    }

    /**
     * Los anticipos recibidos en esta sesión.
     *
     * Es plata que entró de verdad al turno aunque todavía no pague ninguna
     * factura, así que cuenta para el arqueo igual que cualquier cobro.
     *
     * @param  array{count:int, total:float, by_method:array<string,float>, cash:float}  $resumen
     * @return array{count:int, total:float, by_method:array<string,float>, cash:float}
     */
    protected function sumarAnticipos(array $resumen, CashRegisterSession $session): array
    {
        $anticipos = CustomerAdvance::query()
            ->where('cash_register_session_id', $session->id)
            ->get(['amount', 'payment_method']);

        foreach ($anticipos as $anticipo) {
            // Un anticipo viejo puede no tener metodo guardado. Se agrupa
            // aparte en vez de sumarlo a efectivo: darlo por efectivo cuando
            // no se sabe descuadra el arqueo en la direccion peligrosa.
            $metodo = $anticipo->payment_method ?: 'other';
            $monto = (float) $anticipo->amount;

            $resumen['by_method'][$metodo] = round(
                ($resumen['by_method'][$metodo] ?? 0) + $monto, 2
            );

            if ($metodo === 'cash') {
                $resumen['cash'] = round($resumen['cash'] + $monto, 2);
            }
        }

        $resumen['count'] += $anticipos->count();
        $resumen['total'] = round(array_sum($resumen['by_method']), 2);

        return $resumen;
    }

    /**
     * Pagos REALIZADOS a facturas de compra en esta sesión (egreso).
     */
    protected function summarizePurchasePayments(CashRegisterSession $session): array
    {
        $payments = Payment::query()
            ->where('cash_register_session_id', $session->id)
            ->where('paymentable_type', PurchaseInvoice::class)
            ->get(['amount', 'payment_method', 'paymentable_id']);

        return $this->groupPayments($payments);
    }

    /**
     * Gastos posteados en esta sesión. El gasto en sí ES el egreso —
     * no usamos la tabla payments para gastos porque el gasto siempre
     * se paga de contado al registrarlo.
     */
    protected function summarizeExpenses(CashRegisterSession $session): array
    {
        $expenses = Expense::query()
            ->where('cash_register_session_id', $session->id)
            ->where('status', Expense::STATUS_POSTED)
            ->get(['id', 'total', 'payment_method']);

        $byMethod = [];
        $cash = 0.0;
        foreach ($expenses as $e) {
            $method = $e->payment_method;
            $amount = (float) $e->total;
            $byMethod[$method] = ($byMethod[$method] ?? 0) + $amount;
            if ($method === 'cash') {
                $cash += $amount;
            }
        }

        return [
            'count' => $expenses->count(),
            'total' => round($expenses->sum(fn ($e) => (float) $e->total), 2),
            'by_method' => $byMethod,
            'cash' => round($cash, 2),
        ];
    }

    /**
     * Forma común: dado un Collection de Payment, agrupa por método y suma.
     */
    protected function groupPayments($payments): array
    {
        $byMethod = [];
        $cash = 0.0;
        $docs = [];

        foreach ($payments as $p) {
            $method = $p->payment_method;
            $amount = (float) $p->amount;
            $byMethod[$method] = ($byMethod[$method] ?? 0) + $amount;
            if ($method === 'cash') {
                $cash += $amount;
            }
            $docs[$p->paymentable_id] = true;
        }

        return [
            'count' => count($docs),
            'total' => round(array_sum($byMethod), 2),
            'by_method' => array_map(fn ($v) => round($v, 2), $byMethod),
            'cash' => round($cash, 2),
        ];
    }
}
