<?php

namespace App\Services\Sales;

use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use RuntimeException;

/**
 * Reserva el consecutivo de una factura tomándolo de la resolución
 * (POS o Electrónica) asignada a la sede.
 *
 * Reemplaza al numerador interno simple (max+1) para facturas: ahora la
 * numeración sale del rango autorizado por la resolución correspondiente.
 *
 * Resultado: ['number', 'prefix', 'resolution_id', 'kind'].
 * Lanza RuntimeException si la sede no tiene resolución activa del tipo
 * pedido o si la resolución se agotó.
 */
class DocumentNumberer
{
    /**
     * @param  int     $locationId  Sede donde se emite la factura.
     * @param  string  $kind        Resolution::KIND_POS | KIND_ELECTRONIC.
     * @param  int     $documentTypeId  1 = Factura (default).
     */
    public function reserveForLocation(int $locationId, string $kind, int $documentTypeId = 1): array
    {
        if (! array_key_exists($kind, Resolution::KINDS)) {
            throw new RuntimeException('Tipo de resolución inválido.');
        }

        $kindLabel = $kind === Resolution::KIND_POS ? 'POS' : 'de facturación electrónica';

        $locRes = LocationResolution::query()
            ->where('location_id', $locationId)
            ->where('active', true)
            ->whereHas('resolution', fn ($q) => $q
                ->where('kind', $kind)
                ->where('document_type_id', $documentTypeId)
                ->where('active', true))
            ->with('resolution')
            ->first();

        if (! $locRes || ! $locRes->resolution) {
            throw new RuntimeException(
                "Esta sede no tiene una resolución {$kindLabel} activa asignada. "
                .($kind === Resolution::KIND_POS
                    ? 'Configúrala en Ventas → Resoluciones POS.'
                    : 'Configúrala en Facturación Electrónica DIAN.')
            );
        }

        $resolution = $locRes->resolution;
        $number = $locRes->reserveNextNumberInRange();

        return [
            'number' => $number,
            'prefix' => $resolution->prefix,
            'resolution_id' => $resolution->id,
            'kind' => $kind,
        ];
    }

    /**
     * ¿La sede tiene una resolución activa de este tipo? Útil para la UI
     * (deshabilitar el selector, mostrar avisos) sin reservar nada.
     */
    public function hasResolution(int $locationId, string $kind, int $documentTypeId = 1): bool
    {
        return LocationResolution::query()
            ->where('location_id', $locationId)
            ->where('active', true)
            ->whereHas('resolution', fn ($q) => $q
                ->where('kind', $kind)
                ->where('document_type_id', $documentTypeId)
                ->where('active', true))
            ->exists();
    }
}
