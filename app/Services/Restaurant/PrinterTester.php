<?php

namespace App\Services\Restaurant;

use App\Models\Restaurant\Printer;
use App\Services\Restaurant\Concerns\SendsEscPos;

/**
 * Imprime una página de prueba en una impresora para validar la
 * configuración. Soporta los 3 modos:
 *  - network/cups: imprime server-side y reporta éxito/error.
 *  - browser: encola el job en BrowserPrintQueue para QZ Tray; la
 *    página Livewire debe despachar el evento después.
 */
class PrinterTester
{
    use SendsEscPos;

    /**
     * @return array{mode:string, ok:bool, message:string}
     */
    public function test(Printer $printer): array
    {
        $payload = $this->buildTestPayload($printer);

        if ($printer->connection_type === 'browser') {
            if (! $printer->printer_name) {
                return ['mode' => 'browser', 'ok' => false, 'message' => 'Falta el nombre de la impresora en Windows.'];
            }
            app(BrowserPrintQueue::class)->push(
                $printer->printer_name,
                $payload,
                'Prueba — '.$printer->name,
            );
            return ['mode' => 'browser', 'ok' => true, 'message' => 'Job encolado para QZ Tray.'];
        }

        try {
            $this->dispatchToPrinter($printer, $payload);
            return ['mode' => 'server', 'ok' => true, 'message' => 'Impreso correctamente.'];
        } catch (\Throwable $e) {
            return ['mode' => 'server', 'ok' => false, 'message' => $e->getMessage()];
        }
    }

    protected function buildTestPayload(Printer $printer): string
    {
        $b = new EscPosBuilder((int) ($printer->columns ?: 48));

        $b->alignCenter()->bold(true)->size(2, 2)
            ->line('** PRUEBA **')
            ->size(1, 1)->bold(false)
            ->line('Emprenddi')
            ->separator('=');

        $b->alignLeft()
            ->line('Impresora: '.$printer->name)
            ->line('Proposito: '.(Printer::PURPOSES[$printer->purpose] ?? $printer->purpose))
            ->line('Conexion: '.(Printer::CONNECTION_TYPES[$printer->connection_type] ?? $printer->connection_type))
            ->line('Fecha: '.now()->format('Y-m-d H:i:s'))
            ->separator('-');

        $b->alignCenter()
            ->bold(true)->line('Si lees esto,')->line('la impresora funciona OK')->bold(false)
            ->lf()
            ->line('ABCDEFGHIJKLMNOPQRSTUVWXYZ')
            ->line('0123456789  $.,:-+')
            ->line('Tildes: aeiou n')
            ->lf();

        $b->cut();

        return $b->getBytes();
    }
}
