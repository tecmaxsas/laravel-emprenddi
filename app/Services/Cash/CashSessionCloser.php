<?php

namespace App\Services\Cash;

use App\Models\CashRegisterSession;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Cierra una sesion de caja calculando esperado vs contado y persistiendo
 * el snapshot de ventas/pagos. Usado por el POS tradicional, el POS
 * restaurante y el terminal de parqueadero — misma logica en un solo
 * lugar para evitar divergencia entre modulos.
 */
class CashSessionCloser
{
    public function __construct(protected CashSessionSummary $summary) {}

    /**
     * @return array{expected: float, counted: float, difference: float, summary: array}
     */
    public function close(CashRegisterSession $session, float $counted, ?string $notes = null): array
    {
        if ($session->status !== CashRegisterSession::STATUS_OPEN) {
            throw new RuntimeException('La sesión de caja ya está cerrada.');
        }

        $summary = $this->summary->compute($session);
        $expected = (float) $summary['expected_cash'];
        $difference = round($counted - $expected, 2);

        $session->update([
            'status' => CashRegisterSession::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by_user_id' => Auth::id(),
            'closing_expected' => $expected,
            'closing_counted' => $counted,
            'closing_difference' => $difference,
            'total_sales' => $summary['sales']['total'],
            'invoice_count' => $summary['sales']['count'],
            'payment_breakdown' => $summary['payment_breakdown'],
            'closing_notes' => $notes ? trim($notes) : null,
        ]);

        return [
            'expected' => $expected,
            'counted' => $counted,
            'difference' => $difference,
            'summary' => $summary,
        ];
    }
}
