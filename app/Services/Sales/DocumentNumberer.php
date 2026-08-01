<?php

namespace App\Services\Sales;

use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\Location;
use App\Models\SaleInvoice;
use Illuminate\Support\Facades\DB;
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

        // Resolver company_id de la sede PRIMERO — cimiento de todo el resto:
        // filtramos LocationResolution -> Resolution por este company_id de
        // manera explicita para no depender del CompanyScope global (que no
        // aplica cuando CurrentCompany no esta seteado — p. ej. superadmin).
        $companyId = (int) Location::query()->where('id', $locationId)->value('company_id');
        if ($companyId <= 0) {
            throw new RuntimeException('La sede no tiene empresa asociada.');
        }

        $locRes = LocationResolution::query()
            ->where('location_id', $locationId)
            ->where('active', true)
            ->whereHas('resolution', fn ($q) => $q
                ->where('company_id', $companyId)
                ->where('kind', $kind)
                ->where('document_type_id', $documentTypeId)
                ->where('active', true))
            ->with(['resolution' => fn ($q) => $q->where('company_id', $companyId)])
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

        // Doble candado: la Resolution debe pertenecer a la misma empresa que
        // la Location. Si por algun motivo hay una asociacion cross-tenant en
        // la BD, cortamos aqui en vez de emitir con el prefix equivocado.
        if ((int) $resolution->company_id !== $companyId) {
            throw new RuntimeException(
                'Inconsistencia: la resolución asociada a la sede es de otra empresa. '
                .'Contacta al administrador.'
            );
        }

        // Auto-saneo defensivo: si el current_consecutive quedo desactualizado
        // (rollback pasado, carga manual, contador reiniciado) apuntando a un
        // numero que ya existe en sale_invoices, saltamos al max_existente + 1
        // ANTES de reservar — todo dentro del lock para evitar race conditions.
        //
        // NOTA: el index unico en sale_invoices es (company_id, prefix, number)
        // — SIN invoice_kind. Por eso el max se saca solo con company+prefix,
        // no filtramos por kind (si dos resoluciones distintas comparten prefix
        // igual seguiriamos chocando).
        $number = DB::transaction(function () use ($locRes, $resolution, $companyId, $kindLabel) {
            $locked = LocationResolution::query()
                ->where('id', $locRes->id)
                ->lockForUpdate()
                ->first();

            $current = (int) $locked->current_consecutive;

            $maxUsed = SaleInvoice::query()
                ->where('company_id', $companyId)
                ->where('prefix', $resolution->prefix)
                ->max('number');

            if ($maxUsed !== null && $current <= (int) $maxUsed) {
                $current = (int) $maxUsed + 1;
            }

            if ($current > (int) $resolution->range_to) {
                throw new RuntimeException(
                    "La resolución {$resolution->prefix} {$kindLabel} se agotó "
                    ."(rango {$resolution->range_from} – {$resolution->range_to}). "
                    .'Carga una resolución nueva.'
                );
            }

            $locked->update(['current_consecutive' => $current + 1]);

            return $current;
        });

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
        $companyId = (int) Location::query()->where('id', $locationId)->value('company_id');
        if ($companyId <= 0) return false;

        return LocationResolution::query()
            ->where('location_id', $locationId)
            ->where('active', true)
            ->whereHas('resolution', fn ($q) => $q
                ->where('company_id', $companyId)
                ->where('kind', $kind)
                ->where('document_type_id', $documentTypeId)
                ->where('active', true))
            ->exists();
    }
}
