<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\Printer;
use Illuminate\Support\Facades\Log;

/**
 * Imprime KitchenTickets en la impresora destino.
 *
 * Soporta conexiones:
 *   - network: TCP al host:port, raw ESC/POS (estándar Epson/Star/Star)
 *   - cups: envía vía cola CUPS local (requiere lp/lpr disponibles en el container)
 *   - browser: NO se imprime server-side (la impresión la dispara un agente
 *     local tipo QZ Tray desde el cliente — futuro)
 *
 * Cuando falla, el ticket queda con status='failed' y error_message
 * con el detalle. El item igual se marca como sent porque el cocinero
 * puede ver la orden desde el KDS aunque el papel no salga.
 */
class KitchenTicketPrinter
{
    /**
     * Imprime un ticket. Actualiza ticket->status y error_message
     * según resultado. NO lanza excepción para no abortar el flujo
     * de "enviar a cocina" — devuelve bool.
     */
    public function print(KitchenTicket $ticket): bool
    {
        $printer = $ticket->printer;
        if (! $printer) {
            $this->markFailed($ticket, 'Impresora no encontrada (relación nula).');
            return false;
        }

        if (! $printer->active) {
            $this->markFailed($ticket, 'Impresora inactiva.');
            return false;
        }

        if ($printer->connection_type === 'browser') {
            // Modo browser: no imprimimos server-side. Dejamos el ticket
            // como printed (válido) y el front-end (QZ Tray) lo recoge.
            return true;
        }

        try {
            $payload = $this->buildPayload($ticket, $printer);

            if ($printer->connection_type === 'network') {
                $this->sendTcp($printer->host, (int) $printer->port, $payload);
            } elseif ($printer->connection_type === 'cups') {
                $this->sendCups($printer->cups_queue, $payload);
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('Kitchen ticket print failed', [
                'ticket_id' => $ticket->id,
                'printer' => $printer->name,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($ticket, substr($e->getMessage(), 0, 500));
            return false;
        }
    }

    /**
     * Imprime todos los tickets de un batch. Devuelve cuántos OK.
     */
    public function printBatch(array $tickets): array
    {
        $ok = 0;
        $failed = [];
        foreach ($tickets as $t) {
            if ($this->print($t)) {
                $ok++;
            } else {
                $failed[] = $t->fresh()->error_message ?? 'desconocido';
            }
        }
        return ['ok' => $ok, 'failed' => $failed];
    }

    protected function buildPayload(KitchenTicket $ticket, Printer $printer): string
    {
        $b = new EscPosBuilder((int) $printer->columns);
        $order = $ticket->order;
        $tableLabel = $order->table?->code ?? ($order->is_delivery ? 'DELIVERY' : 'TAKEAWAY');

        // Header — gigante, centrado
        $b->alignCenter()->bold(true)->size(2, 2)
            ->line($printer->name);

        // Si es para llevar, banderazo grande arriba del numero de mesa
        if ($order->is_takeaway) {
            $b->size(2, 3)->line('** PARA LLEVAR **');
        } elseif ($order->is_delivery) {
            $b->size(2, 3)->line('** DELIVERY **');
        }

        $b->size(3, 3)->line("MESA {$tableLabel}");
        $b->size(1, 1)->bold(false)
            ->line($order->fullNumber())
            ->line(now()->format('Y-m-d H:i:s'))
            ->line('Mesero: '.($order->server?->name ?? '—'))
            ->line($order->guests.' comensal'.($order->guests > 1 ? 'es' : ''));

        // Nombre del cliente si es takeaway/delivery con metadata
        $custName = $order->delivery_metadata['customer_name'] ?? null;
        if ($custName) {
            $b->bold(true)->line('Cliente: '.$custName)->bold(false);
        }

        $b->separator('=')->alignLeft();

        // Agrupar items por curso: imprime un sub-encabezado por curso para
        // que el cocinero secuencie. Si el ticket fue filtrado por un solo
        // curso, igual queda como un sub-bloque con titulo claro.
        $snapshot = $ticket->items_snapshot ?? [];
        $byCourse = [];
        foreach ($snapshot as $it) {
            $c = (int) ($it['course'] ?? 1);
            $byCourse[$c][] = $it;
        }
        ksort($byCourse);

        foreach ($byCourse as $courseNum => $items) {
            $courseName = \App\Models\Restaurant\OrderItem::COURSES[$courseNum] ?? ('CURSO '.$courseNum);
            $b->bold(true)->alignCenter()
                ->line('--- '.strtoupper($courseName).' ---')
                ->bold(false)->alignLeft()->lf();

            foreach ($items as $item) {
                $qty = (float) ($item['quantity'] ?? 1);
                $desc = $item['description'] ?? '?';

                $b->bold(true)->size(2, 2)
                    ->line(rtrim(number_format($qty, 0).'x  '.strtoupper($desc)))
                    ->size(1, 1)->bold(false);

                // Modificadores
                foreach (($item['modifiers'] ?? []) as $mod) {
                    $name = is_array($mod) ? ($mod['name'] ?? '') : (string) $mod;
                    if ($name) $b->line('   + '.$name);
                }

                // Nota
                if (! empty($item['note'])) {
                    $b->bold(true)->line('   NOTA: '.$item['note'])->bold(false);
                }

                // Split tab
                if (! empty($item['split_tab'])) {
                    $b->line('   ('.$item['split_tab'].')');
                }

                $b->lf();
            }
        }

        $b->separator('-');
        $b->alignCenter()
            ->line('Comanda #'.$ticket->batch_number)
            ->line('Pedido a '.now()->format('H:i'))
            ->cut();

        return $b->getBytes();
    }

    /**
     * Envía bytes a una impresora TCP (raw ESC/POS, puerto 9100 típico).
     */
    protected function sendTcp(string $host, int $port, string $payload): void
    {
        if (! $host) {
            throw new \RuntimeException('Host de impresora vacío.');
        }

        $errno = 0;
        $errstr = '';
        // Timeout 3s para no colgar el flujo si la impresora está apagada
        $fp = @fsockopen($host, $port ?: 9100, $errno, $errstr, 3);

        if (! $fp) {
            throw new \RuntimeException("No se pudo conectar a {$host}:{$port} — {$errstr}");
        }

        // No bloqueante para escritura
        stream_set_timeout($fp, 3);
        $written = @fwrite($fp, $payload);
        fclose($fp);

        if ($written === false || $written < 1) {
            throw new \RuntimeException('La impresora rechazó la escritura.');
        }
    }

    /**
     * Envía a una cola CUPS local. Requiere binario `lp` en el container.
     * Soporta containers Alpine con cups-client instalado.
     */
    protected function sendCups(?string $queue, string $payload): void
    {
        if (! $queue) {
            throw new \RuntimeException('Cola CUPS no configurada.');
        }
        if (! function_exists('proc_open')) {
            throw new \RuntimeException('proc_open no disponible — no se puede usar CUPS.');
        }

        $cmd = ['lp', '-d', $queue, '-o', 'raw', '-'];
        $proc = @proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (! is_resource($proc)) {
            throw new \RuntimeException('No se pudo lanzar `lp` (CUPS).');
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) {
            throw new \RuntimeException("CUPS lp falló (exit {$exit}): {$stderr}");
        }
    }

    protected function markFailed(KitchenTicket $ticket, string $error): void
    {
        $ticket->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }
}
