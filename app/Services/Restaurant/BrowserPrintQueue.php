<?php

namespace App\Services\Restaurant;

/**
 * Cola de trabajos de impresión que deben ejecutarse en el NAVEGADOR
 * vía QZ Tray (impresoras con connection_type='browser').
 *
 * Registrada como singleton — vive durante el request. Los printer
 * services acumulan jobs acá cuando la impresora es de tipo browser
 * (no pueden imprimir server-side). La página Livewire, al terminar
 * la acción, lee flush() y dispatcha un evento al front que se lo
 * pasa a QZ Tray.
 *
 * Cada job: ['printer_name' => string, 'payload_b64' => string, 'label' => string]
 */
class BrowserPrintQueue
{
    /** @var array<int, array{printer_name:string, payload_b64:string, label:string}> */
    protected array $jobs = [];

    public function push(string $printerName, string $rawPayload, string $label = ''): void
    {
        $this->jobs[] = [
            'printer_name' => $printerName,
            'payload_b64' => base64_encode($rawPayload),
            'label' => $label,
        ];
    }

    public function isEmpty(): bool
    {
        return empty($this->jobs);
    }

    public function all(): array
    {
        return $this->jobs;
    }

    /**
     * Devuelve los jobs acumulados y limpia la cola.
     */
    public function flush(): array
    {
        $jobs = $this->jobs;
        $this->jobs = [];
        return $jobs;
    }
}
