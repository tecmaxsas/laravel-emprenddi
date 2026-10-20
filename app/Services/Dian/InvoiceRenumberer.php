<?php

namespace App\Services\Dian;

use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\SaleInvoice;
use App\Services\Sales\DocumentNumberer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pasa facturas electrónicas de una resolución a otra, con número nuevo.
 *
 * Nació para TRONITEC: con la empresa ya en producción, 380 facturas salieron
 * numeradas con la resolución de habilitación (SETP990000000…) porque seguía
 * activa y aparecía en el selector de la factura. La DIAN no puede recibir
 * ninguna —FAD05e, «número de factura no existe para el número de
 * autorización»—, y la única salida es darles un número de la resolución de
 * producción y enviarlas.
 *
 * **Qué se puede renumerar.** Solo lo que la DIAN nunca validó: ni aceptadas
 * ni en vuelo. Una aceptada ya existe allá con su número, y cambiarlo aquí deja
 * a los dos sistemas hablando de documentos distintos. Las rechazadas sí: el
 * CUFE que traen es el que calculó el proveedor, no uno que la DIAN reconozca,
 * y se limpia junto con el resto de la respuesta.
 *
 * **Qué se arrastra.** El número viaja escrito en el asiento de la venta, en
 * el de costo de ventas, en sus líneas y en los movimientos de inventario. Se
 * reemplaza ahí con `DB::table` y no con los modelos a propósito: los modelos
 * pasan por FiscalPeriodGuard, que con razón impide tocar un periodo cerrado,
 * pero aquí no se mueve un peso ni una fecha, solo el texto que nombra la
 * factura. Dejarlo con el número viejo haría imposible encontrar el asiento
 * desde la factura. La fecha de la factura y de sus asientos no se toca: los
 * ingresos y el IVA se quedan en el periodo en que se vendió.
 */
class InvoiceRenumberer
{
    public function __construct(
        protected DocumentNumberer $numberer,
    ) {}

    /**
     * Las facturas de la resolución origen que se pueden renumerar, en el orden
     * en que se crearon: así el número nuevo respeta la secuencia real de venta.
     *
     * @return Collection<int, SaleInvoice>
     */
    public function candidatas(Resolution $origen): Collection
    {
        return $this->deOrigen($origen)
            ->filter(fn (SaleInvoice $f) => $this->motivoParaNoTocar($f) === null)
            ->values();
    }

    /**
     * Las que NO se pueden renumerar, con el motivo.
     *
     * @return Collection<int, array{factura: SaleInvoice, motivo: string}>
     */
    public function bloqueadas(Resolution $origen): Collection
    {
        return $this->deOrigen($origen)
            ->map(fn (SaleInvoice $f) => ['factura' => $f, 'motivo' => $this->motivoParaNoTocar($f)])
            ->filter(fn (array $fila) => $fila['motivo'] !== null)
            ->values();
    }

    public function motivoParaNoTocar(SaleInvoice $factura): ?string
    {
        return match ($factura->dian_status) {
            SaleInvoice::DIAN_ACCEPTED => 'la DIAN ya la aceptó con este número',
            SaleInvoice::DIAN_SENT => 'está esperando respuesta de la DIAN: consulta su estado primero',
            default => null,
        };
    }

    /**
     * El primer número que tomaría la resolución destino, sin reservar nada.
     * Misma regla que DocumentNumberer::reserveForResolution(): el mayor entre
     * el inicio del rango, el contador de sus sedes y la última factura emitida.
     */
    public function siguienteNumero(Resolution $destino): int
    {
        $candidatos = [(int) $destino->range_from];

        $contador = LocationResolution::query()
            ->where('dian_resolution_id', $destino->id)
            ->max('current_consecutive');
        if ($contador !== null) {
            $candidatos[] = (int) $contador;
        }

        $maxUsado = DB::table('sale_invoices')
            ->where('company_id', $destino->company_id)
            ->where('prefix', $destino->prefix)
            ->max('number');
        if ($maxUsado !== null) {
            $candidatos[] = (int) $maxUsado + 1;
        }

        return max($candidatos);
    }

    /**
     * Renumera una factura con la resolución destino. Todo o nada: si algo
     * falla, la factura se queda con su número viejo y el consecutivo no se gasta.
     *
     * @return array{viejo: string, nuevo: string}
     */
    public function renumerar(SaleInvoice $factura, Resolution $destino): array
    {
        if ($motivo = $this->motivoParaNoTocar($factura)) {
            throw new RuntimeException("La factura {$factura->fullNumber()} no se puede renumerar: {$motivo}.");
        }

        if ((int) $factura->company_id !== (int) $destino->company_id) {
            throw new RuntimeException('La resolución destino es de otra empresa.');
        }

        if (! $destino->isElectronic() || (int) $destino->document_type_id !== 1) {
            throw new RuntimeException("La resolución {$destino->prefix} no es de factura electrónica.");
        }

        return DB::transaction(function () use ($factura, $destino) {
            $viejo = $factura->fullNumber();

            $reserva = $this->numberer->reserveForResolution(
                $destino->id,
                (int) $factura->company_id,
                $factura->location_id ? (int) $factura->location_id : null,
            );

            // Por el modelo, para que quede en la auditoría quién y cuándo.
            $factura->update([
                'prefix' => $reserva['prefix'],
                'number' => $reserva['number'],
                'dian_resolution_id' => $reserva['resolution_id'],
                // Un borrador sigue siendo borrador: se marcará pendiente al
                // contabilizarse, como cualquier otro.
                'dian_status' => $factura->isPosted() ? SaleInvoice::DIAN_PENDING : null,
                'dian_status_code' => null,
                'cufe' => null,
                'qr_url' => null,
                'dian_response' => null,
                'dian_error_message' => null,
                'dian_sent_at' => null,
            ]);

            $nuevo = $factura->fullNumber();

            $this->arrastrarReferencias($factura, $viejo, $nuevo);

            return ['viejo' => $viejo, 'nuevo' => $nuevo];
        });
    }

    /**
     * Le pone a la factura un número exacto de la resolución destino, sin pasar
     * por el contador. Sirve para lo que el contador no sabe hacer:
     *
     *  - Llevar a POS facturas que nunca debieron ser electrónicas. Fue el caso
     *    de ZOMACTEC, sede de TRONITEC que no factura electrónicamente: sus
     *    facturas salieron con la resolución de pruebas porque la sede no tenía
     *    su resolución POS asignada.
     *  - Cerrar los huecos que esas facturas dejan al salir del rango
     *    electrónico, mientras ninguna de las de arriba se haya enviado.
     *
     * El tipo de la factura lo decide la resolución: con una POS, la factura
     * queda POS y sin estado DIAN, porque no se transmite.
     *
     * Quien llama responde por el orden: el número tiene que estar libre.
     *
     * @return array{viejo: string, nuevo: string}
     */
    public function asignarNumero(SaleInvoice $factura, Resolution $destino, int $numero): array
    {
        if ($motivo = $this->motivoParaNoTocar($factura)) {
            throw new RuntimeException("La factura {$factura->fullNumber()} no se puede renumerar: {$motivo}.");
        }

        if ((int) $factura->company_id !== (int) $destino->company_id || (int) $destino->document_type_id !== 1) {
            throw new RuntimeException("La resolución {$destino->prefix} no sirve para esta factura.");
        }

        if ($numero < (int) $destino->range_from || $numero > (int) $destino->range_to) {
            throw new RuntimeException("{$destino->prefix}{$numero} está fuera del rango de la resolución.");
        }

        return DB::transaction(function () use ($factura, $destino, $numero) {
            $ocupado = DB::table('sale_invoices')
                ->where('company_id', $factura->company_id)
                ->where('prefix', $destino->prefix)
                ->where('number', $numero)
                ->where('id', '!=', $factura->id)
                ->exists();

            if ($ocupado) {
                throw new RuntimeException("{$destino->prefix}{$numero} ya lo tiene otra factura.");
            }

            $viejo = $factura->fullNumber();
            $esPos = $destino->isPos();

            $factura->update([
                'prefix' => $destino->prefix,
                'number' => $numero,
                'dian_resolution_id' => $destino->id,
                'invoice_kind' => $destino->kind,
                'dian_status' => (! $esPos && $factura->isPosted()) ? SaleInvoice::DIAN_PENDING : null,
                'dian_status_code' => null,
                'cufe' => null,
                'qr_url' => null,
                'dian_response' => null,
                'dian_error_message' => null,
                'dian_sent_at' => null,
            ]);

            $nuevo = $factura->fullNumber();

            if ($viejo !== $nuevo) {
                $this->arrastrarReferencias($factura, $viejo, $nuevo);
            }

            return ['viejo' => $viejo, 'nuevo' => $nuevo];
        });
    }

    /** @return Collection<int, SaleInvoice> */
    protected function deOrigen(Resolution $origen): Collection
    {
        return SaleInvoice::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('company_id', $origen->company_id)
            ->where('prefix', $origen->prefix)
            ->where('invoice_kind', 'electronic')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    protected function arrastrarReferencias(SaleInvoice $factura, string $viejo, string $nuevo): void
    {
        $cambiar = fn (?string $texto) => $texto === null
            ? null
            // (?!\d): que SETP99000001 no se coma el comienzo de SETP990000010.
            : preg_replace('/'.preg_quote($viejo, '/').'(?!\d)/', $nuevo, $texto);

        $asientos = DB::table('journal_entries')
            ->where('company_id', $factura->company_id)
            ->where(fn ($q) => $q
                ->where('reference', $viejo)
                ->when($factura->journal_entry_id, fn ($q) => $q->orWhere('id', $factura->journal_entry_id)))
            ->get(['id', 'reference', 'description']);

        foreach ($asientos as $asiento) {
            DB::table('journal_entries')->where('id', $asiento->id)->update([
                'reference' => $cambiar($asiento->reference),
                'description' => $cambiar($asiento->description),
            ]);
        }

        if ($asientos->isNotEmpty()) {
            DB::table('journal_entry_lines')
                ->whereIn('journal_entry_id', $asientos->pluck('id'))
                ->where('description', 'like', '%'.$viejo.'%')
                ->get(['id', 'description'])
                ->each(fn ($linea) => DB::table('journal_entry_lines')
                    ->where('id', $linea->id)
                    ->update(['description' => $cambiar($linea->description)]));
        }

        DB::table('inventory_movements')
            ->where('company_id', $factura->company_id)
            ->where('reference_type', SaleInvoice::class)
            ->where('reference_id', $factura->id)
            ->get(['id', 'reference_number', 'description'])
            ->each(fn ($mov) => DB::table('inventory_movements')
                ->where('id', $mov->id)
                ->update([
                    'reference_number' => $cambiar($mov->reference_number),
                    'description' => $cambiar($mov->description),
                ]));
    }
}
