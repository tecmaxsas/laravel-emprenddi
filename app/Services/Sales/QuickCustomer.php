<?php

namespace App\Services\Sales;

use App\Models\ThirdParty;
use App\Support\DianDvCalculator;
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
     * @return array{customer: ThirdParty, existed: bool}
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
        $existente = ThirdParty::query()
            ->where('company_id', $companyId)
            ->where('document_number', $documento)
            ->first();

        if ($existente) {
            return ['customer' => $existente, 'existed' => true];
        }

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

        return ['customer' => $cliente, 'existed' => false];
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
