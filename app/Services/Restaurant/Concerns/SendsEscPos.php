<?php

namespace App\Services\Restaurant\Concerns;

/**
 * Transporte raw ESC/POS compartido entre KitchenTicketPrinter y
 * RestaurantReceiptPrinter. Soporta TCP (puerto 9100) y CUPS.
 */
trait SendsEscPos
{
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
        // Timeout 3s para no colgar el flujo si la impresora está apagada.
        $fp = @fsockopen($host, $port ?: 9100, $errno, $errstr, 3);

        if (! $fp) {
            throw new \RuntimeException("No se pudo conectar a {$host}:{$port} — {$errstr}");
        }

        stream_set_timeout($fp, 3);
        $written = @fwrite($fp, $payload);
        fclose($fp);

        if ($written === false || $written < 1) {
            throw new \RuntimeException('La impresora rechazó la escritura.');
        }
    }

    /**
     * Envía a una cola CUPS local. Requiere binario `lp` en el container.
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
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) {
            throw new \RuntimeException("CUPS lp falló (exit {$exit}): {$stderr}");
        }
    }

    /**
     * Envía un payload a una impresora según su connection_type.
     * Devuelve true si imprimió (o si era browser, que no imprime
     * server-side). Lanza excepción en caso de fallo real de TCP/CUPS.
     */
    protected function dispatchToPrinter(\App\Models\Restaurant\Printer $printer, string $payload): bool
    {
        if ($printer->connection_type === 'browser') {
            return true; // browser imprime client-side (QZ Tray) — no aplica acá
        }

        if ($printer->connection_type === 'network') {
            $this->sendTcp((string) $printer->host, (int) $printer->port, $payload);
        } elseif ($printer->connection_type === 'cups') {
            $this->sendCups($printer->cups_queue, $payload);
        } else {
            throw new \RuntimeException("Tipo de conexión desconocido: {$printer->connection_type}");
        }

        return true;
    }
}
