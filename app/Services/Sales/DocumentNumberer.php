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
     * @param  int  $locationId  Sede donde se emite la factura.
     * @param  string  $kind  Resolution::KIND_POS | KIND_ELECTRONIC.
     * @param  int  $documentTypeId  1 = Factura (default).
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
     * Reserva el consecutivo de una resolución concreta, esté o no asignada a
     * la sede desde la que se factura.
     *
     * Existe porque la asignación resolución↔sede es una comodidad, no una
     * regla del negocio: una empresa puede tener varias resoluciones vigentes y
     * necesitar emitir con una determinada —la del contrato de un cliente, la
     * que está por vencerse y hay que agotar, la de una sede que factura desde
     * otra— sin tener que reasignarla y volver a dejarla como estaba.
     *
     * **De dónde sale el número cuando no hay asignación.** El contador vive en
     * `dian_location_resolutions.current_consecutive`, que solo existe si la
     * resolución está asignada a alguna sede. Sin asignación no hay contador, y
     * por eso aquí el siguiente número se deduce de tres fuentes y se toma la
     * mayor:
     *
     *   1. el contador más alto entre sus asignaciones, si las tiene;
     *   2. la factura más alta ya emitida con ese prefijo en la empresa;
     *   3. el inicio del rango autorizado.
     *
     * Deducirlo de la realidad y no de un contador suelto es lo que impide
     * repetir un número: si alguien emitió por la vía de la sede, esta vía lo
     * ve, y al revés.
     *
     * Si se indica la sede, la asignación (sede, resolución) se crea si no
     * existía y su contador queda al día. Se crea **inactiva**: sirve para
     * llevar la cuenta, no para convertirse en la resolución por defecto de esa
     * sede. Facturar una vez con otra resolución no debería cambiar en silencio
     * con cuál factura esa sede de ahí en adelante.
     *
     * @param  int  $resolutionId  La resolución elegida en el formulario.
     * @param  int  $companyId  La empresa del usuario, para no confiar en el id que llega.
     * @param  int|null  $locationId  La sede desde la que se emite.
     * @param  string  $tabla  Dónde buscar el número más alto ya emitido con ese
     *                         prefijo. Las notas crédito llevan su propia
     *                         numeración, en su propia tabla: mirar la de
     *                         facturas les daría un consecutivo ajeno.
     * @return array{number: int, prefix: string, resolution_id: int, kind: string}
     */
    public function reserveForResolution(
        int $resolutionId,
        int $companyId,
        ?int $locationId = null,
        string $tabla = 'sale_invoices',
    ): array {
        $resolution = Resolution::query()
            ->withoutGlobalScopes()
            ->where('id', $resolutionId)
            ->where('company_id', $companyId)
            ->first();

        if (! $resolution) {
            throw new RuntimeException('La resolución seleccionada no existe o no es de tu empresa.');
        }

        if (! $resolution->active) {
            throw new RuntimeException(
                "La resolución {$resolution->prefix} está inactiva. Actívala o elige otra."
            );
        }

        $kindLabel = $resolution->isPos() ? 'POS' : 'de facturación electrónica';

        return DB::transaction(function () use ($resolution, $companyId, $kindLabel, $locationId, $tabla) {
            // Sin fila de asignación no hay nada que bloquear, así que el
            // candado va sobre (empresa, prefijo), que es justamente lo que
            // protege el índice único de sale_invoices.
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [
                    crc32('res:'.$companyId.':'.$resolution->prefix),
                ]);
            }

            // La asignacion de ESTA sede se crea si no existia, para que la
            // resolucion tenga contador propio de aqui en adelante. Inactiva:
            // lleva la cuenta sin volverse la predeterminada de la sede.
            if ($locationId) {
                $pertenece = Location::query()
                    ->where('id', $locationId)
                    ->where('company_id', $companyId)
                    ->exists();

                if ($pertenece) {
                    LocationResolution::query()->firstOrCreate(
                        ['location_id' => $locationId, 'dian_resolution_id' => $resolution->id],
                        ['current_consecutive' => (int) $resolution->range_from, 'active' => false],
                    );
                }
            }

            $asignaciones = LocationResolution::query()
                ->where('dian_resolution_id', $resolution->id)
                ->lockForUpdate()
                ->get();

            $candidatos = [(int) $resolution->range_from];

            if ($asignaciones->isNotEmpty()) {
                $candidatos[] = (int) $asignaciones->max('current_consecutive');
            }

            $maxUsado = DB::table($tabla)
                ->where('company_id', $companyId)
                ->where('prefix', $resolution->prefix)
                ->max('number');

            if ($maxUsado !== null) {
                $candidatos[] = (int) $maxUsado + 1;
            }

            $numero = max($candidatos);

            if ($numero > (int) $resolution->range_to) {
                throw new RuntimeException(
                    "La resolución {$resolution->prefix} {$kindLabel} se agotó "
                    ."(rango {$resolution->range_from} – {$resolution->range_to}). "
                    .'Carga una resolución nueva.'
                );
            }

            // Las asignaciones se adelantan también: si mañana se factura por
            // la vía de la sede, tiene que arrancar donde quedó esta.
            foreach ($asignaciones as $asignacion) {
                if ((int) $asignacion->current_consecutive <= $numero) {
                    $asignacion->update(['current_consecutive' => $numero + 1]);
                }
            }

            return [
                'number' => $numero,
                'prefix' => $resolution->prefix,
                'resolution_id' => $resolution->id,
                'kind' => $resolution->kind,
            ];
        });
    }

    /**
     * ¿La sede tiene una resolución activa de este tipo? Útil para la UI
     * (deshabilitar el selector, mostrar avisos) sin reservar nada.
     */
    /**
     * La resolución de la empresa para un tipo de documento global.
     *
     * Notas crédito, notas débito, documento soporte y nómina llevan un solo
     * consecutivo para toda la empresa: la DIAN autoriza esos rangos a nombre
     * del contribuyente, no de cada establecimiento. Buscarlas por sede es lo
     * que dejaba las notas sin resolución y, más tarde, rechazadas.
     *
     * Si hay más de una vigente se prefiere la que todavía tiene rango
     * disponible, y entre esas la más reciente. Tener dos activas del mismo tipo
     * es raro y casi siempre significa que acaban de cargar el reemplazo de una
     * que se está agotando; en ese caso seguir usando la vieja hasta que se
     * acabe es justo lo que se espera.
     */
    public function resolucionGlobalDe(int $companyId, int $documentTypeId, string $tabla): ?Resolution
    {
        $candidatas = Resolution::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('document_type_id', $documentTypeId)
            ->where('active', true)
            ->orderByDesc('date_from')
            ->orderByDesc('id')
            ->get();

        if ($candidatas->isEmpty()) {
            return null;
        }

        $conCupo = $candidatas->first(function (Resolution $resolucion) use ($companyId, $tabla) {
            $maxUsado = DB::table($tabla)
                ->where('company_id', $companyId)
                ->where('prefix', $resolucion->prefix)
                ->max('number');

            $siguiente = $maxUsado !== null
                ? (int) $maxUsado + 1
                : (int) $resolucion->range_from;

            return $siguiente <= (int) $resolucion->range_to;
        });

        // Si ninguna tiene cupo se devuelve una igual: que el error diga
        // «se agotó el rango» es más útil que un silencioso «no hay resolución».
        return $conCupo ?? $candidatas->first();
    }

    /**
     * Reserva el consecutivo de un documento de numeración global.
     *
     * Devuelve null cuando la empresa no tiene resolución de ese tipo — es una
     * situación normal en empresas que todavía no emiten ese documento
     * electrónicamente, y en ese caso quien llama se queda con su número manual.
     *
     * @return array{number: int, prefix: string, resolution_id: int, kind: string}|null
     */
    public function reserveGlobal(int $companyId, int $documentTypeId, string $tabla): ?array
    {
        $resolucion = $this->resolucionGlobalDe($companyId, $documentTypeId, $tabla);

        if (! $resolucion) {
            return null;
        }

        // locationId null a propósito: estas resoluciones no se asignan a
        // ninguna sede, así que tampoco hay que crearle una asignación.
        return $this->reserveForResolution(
            $resolucion->id,
            $companyId,
            locationId: null,
            tabla: $tabla,
        );
    }

    public function hasResolution(int $locationId, string $kind, int $documentTypeId = 1): bool
    {
        $companyId = (int) Location::query()->where('id', $locationId)->value('company_id');
        if ($companyId <= 0) {
            return false;
        }

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
