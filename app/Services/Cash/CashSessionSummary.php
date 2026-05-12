<?php

namespace App\Services\Cash;

use App\Models\CashRegisterSession;
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
     * Pagos RECIBIDOS de facturas de venta en esta sesión.
     * Agrupa por método. El "cash" entra a la caja física.
     */
    protected function summarizeSalePayments(CashRegisterSession $session): array
    {
        $payments = Payment::query()
            ->where('cash_register_session_id', $session->id)
            ->where('paymentable_type', SaleInvoice::class)
            ->get(['amount', 'payment_method', 'paymentable_id']);

        return $this->groupPayments($payments);
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
