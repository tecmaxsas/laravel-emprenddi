<?php

namespace App\Services\Sales;

use App\Models\Scopes\CompanyScope;
use App\Models\ThirdParty;
use App\Support\DianDvCalculator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Buscar y crear clientes sin salir de una venta.
 *
 * Los tres POS —retail, restaurante y parqueadero— necesitan lo mismo, y
 * copiar la regla en cada uno es como terminan comportandose distinto: el
 * cajero aprende un flujo en una caja y se encuentra otro en la de al lado.
 *
 * Los obligatorios son nombre, tipo y numero de documento y correo. El correo
 * porque la factura electronica se le envia al adquiriente y la DIAN lo exige;
 * pedirlo despues obliga a interrumpir la venta, que es justo lo que se quiere
 * evitar. Lo demas queda opcional a proposito: el cajero tiene la fila
 * esperando.
 */
class QuickCustomer
{
    /**
     * Clientes que coinciden con el termino.
     *
     * @return Collection<int, ThirdParty>
     */
    public function search(int $companyId, string $termino, int $limite = 8): Collection
    {
        $termino = trim($termino);

        // Con menos de tres caracteres la busqueda trae medio directorio y no
        // ayuda a nadie.
        if (mb_strlen($termino) < 3) {
            return collect();
        }

        return ThirdParty::query()
            ->where('company_id', $companyId)
            ->where('is_customer', true)
            ->where('active', true)
            ->where(function ($q) use ($termino) {
                $q->where('name', 'ilike', "%{$termino}%")
                    ->orWhere('document_number', 'like', "%{$termino}%")
                    ->orWhere('email', 'ilike', "%{$termino}%");
            })
            ->orderBy('name')
            ->limit($limite)
            ->get();
    }

    /**
     * Crea el cliente, o devuelve el que ya tenia ese documento.
     *
     * @param  array{name?:string, document_type?:string, document_number?:string, email?:string, phone?:string, address?:string}  $datos
     * @return array{customer: ThirdParty, existed: bool, note: string|null}
     *
     * @throws RuntimeException con un mensaje que se pueda mostrar tal cual.
     */
    public function create(int $companyId, array $datos): array
    {
        $nombre = trim($datos['name'] ?? '');
        $documento = trim($datos['document_number'] ?? '');
        $correo = trim($datos['email'] ?? '');
        $tipo = $datos['document_type'] ?? '';

        $this->exigirDatos($nombre, $tipo, $documento, $correo);

        // Un documento repetido NO se pisa: el cliente que ya esta registrado
        // suele tener mas informacion de la que cabe digitar en una fila del
        // POS, y sobrescribirla con lo poco que se alcanzo a escribir seria
        // perder datos sin avisar.
        //
        // La busqueda incluye los ELIMINADOS y no depende del scope de empresa.
        // El indice unico de la base es (empresa, tipo, numero) y no excluye
        // los borrados, asi que un tercero eliminado bloquea la creacion de
        // uno nuevo con el mismo documento. Buscando solo entre los vivos, el
        // POS no lo encontraba, intentaba insertar, y al cajero le salia el
        // SQL crudo del error de clave duplicada en plena fila.
        $existente = ThirdParty::withTrashed()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('document_number', $documento)
            // Si hay varios con el mismo numero y distinto tipo, manda el que
            // coincide en tipo: es el que choca contra el indice unico.
            ->orderByRaw('CASE WHEN document_type = ? THEN 0 ELSE 1 END', [$tipo])
            ->first();

        if ($existente) {
            return [
                'customer' => $existente,
                'existed' => true,
                'note' => $this->reutilizar($existente),
            ];
        }

        try {
            $cliente = ThirdParty::create([
                'company_id' => $companyId,
                'person_type' => $tipo === 'nit' ? 'juridica' : 'natural',
                'document_type' => $tipo,
                'document_number' => $documento,
                // El NIT lleva digito de verificacion y la DIAN lo valida. Se
                // calcula: es una cuenta, no un dato que el cajero deba saberse.
                'dv' => $tipo === 'nit' ? DianDvCalculator::calculate($documento) : null,
                'name' => $nombre,
                'email' => $correo,
                'phone' => trim($datos['phone'] ?? '') ?: null,
                'address' => trim($datos['address'] ?? '') ?: null,
                'is_customer' => true,
                'is_supplier' => false,
                'active' => true,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Red de seguridad. La busqueda de arriba deberia haberlo
            // encontrado, pero si dos cajeros crean el mismo documento a la
            // vez, o aparece un caso que no previmos, el cajero tiene que leer
            // que paso y no el SQL. Y `QueryException` hereda de
            // `RuntimeException`, asi que los catch de las pantallas lo toman
            // igual que cualquier otro error nuestro.
            throw new RuntimeException(
                'Ya existe un tercero con el documento '.$documento.' en esta empresa. '
                .'Búscalo en la lista en vez de crearlo.'
            );
        }

        return ['customer' => $cliente, 'existed' => false, 'note' => null];
    }

    /**
     * Deja utilizable un tercero que ya existía, y cuenta qué hubo que hacer.
     *
     * Son dos situaciones reales que terminaban en un error de base de datos
     * sin salida:
     *
     *  - El tercero estaba **eliminado**. El índice único lo sigue contando,
     *    así que no se podía crear otro con ese documento ni usar el que
     *    había. Se restaura: es preferible a dejar al cajero atascado, y se
     *    le avisa para que sepa qué pasó.
     *  - El tercero existe pero **solo como proveedor**. Es el mismo señor;
     *    marcarlo también como cliente es lo que el usuario está pidiendo al
     *    intentar crearlo.
     *
     * @return string|null qué se hizo, para decírselo al usuario.
     */
    protected function reutilizar(ThirdParty $tercero): ?string
    {
        $hecho = [];

        if ($tercero->trashed()) {
            $tercero->restore();
            $hecho[] = 'estaba eliminado y se restauró';
        }

        if (! $tercero->is_customer) {
            $tercero->is_customer = true;
            $hecho[] = 'estaba registrado solo como proveedor y ahora también es cliente';
        }

        if (! $tercero->active) {
            $tercero->active = true;
            $hecho[] = 'estaba inactivo y se reactivó';
        }

        if ($tercero->isDirty()) {
            $tercero->save();
        }

        return $hecho === [] ? null : ucfirst(implode('; ', $hecho)).'.';
    }

    protected function exigirDatos(string $nombre, string $tipo, string $documento, string $correo): void
    {
        $faltan = [];

        if ($nombre === '') {
            $faltan[] = 'nombre';
        }
        if (! isset(ThirdParty::DOCUMENT_TYPES[$tipo])) {
            $faltan[] = 'tipo de documento';
        }
        if ($documento === '') {
            $faltan[] = 'número de documento';
        }
        if ($correo === '') {
            $faltan[] = 'correo';
        }

        if ($faltan !== []) {
            throw new RuntimeException('Sin '.implode(', ', $faltan).' no se puede crear el cliente.');
        }

        if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'El correo no es válido. Revísalo: a esa dirección se le envía la factura electrónica.'
            );
        }
    }
}
