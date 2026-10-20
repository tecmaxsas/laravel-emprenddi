<?php

namespace Tests\Unit;

use App\Services\Dian\DianInvoiceSender;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Enviar una factura atrasada con la fecha del envío.
 *
 * La DIAN rechaza la emisión que no coincide con la firma (FAD09e). Se cambia
 * la fecha Y la hora: la fecha de hoy con la hora de contabilización puede
 * quedar en el futuro, y eso es otro rechazo (117).
 */
class DianFechaEmisionTest extends TestCase
{
    public function test_cambia_fecha_y_hora_juntas(): void
    {
        $payload = DianInvoiceSender::conFechaEmision(
            ['date' => '2026-09-01', 'time' => '23:10:00', 'payment_form' => []],
            Carbon::parse('2026-10-20 09:15:30'),
        );

        $this->assertSame('2026-10-20', $payload['date']);
        $this->assertSame('09:15:30', $payload['time'], 'La hora vieja con la fecha nueva quedaría en el futuro.');
    }

    public function test_corre_solo_los_vencimientos_anteriores_a_la_emision(): void
    {
        $payload = DianInvoiceSender::conFechaEmision(
            ['date' => '2026-09-01', 'time' => '10:00:00', 'payment_form' => [
                ['payment_due_date' => '2026-09-01'],
                ['payment_due_date' => '2026-12-31'],
            ]],
            Carbon::parse('2026-10-20 09:00:00'),
        );

        $this->assertSame('2026-10-20', $payload['payment_form'][0]['payment_due_date']);
        $this->assertSame('2026-12-31', $payload['payment_form'][1]['payment_due_date'],
            'Un crédito que vence después no se toca.');
    }
}
