<?php

namespace App\Services\Restaurant;

use App\Models\ThirdParty;
use Illuminate\Support\Collection;

/**
 * Guarda al cliente de un domicilio como tercero, para que la proxima vez que
 * pida se busque y se precargue en vez de volver a dictarlo todo.
 *
 * El problema es que third_parties.document_number es obligatorio y unico, y
 * un cliente que pide por telefono casi nunca da la cedula. Pedirsela para
 * poder guardarlo seria cambiar la friccion de sitio: el domicilio se toma en
 * treinta segundos por telefono.
 *
 * Por eso el criterio es:
 *  - Con cedula, manda la cedula.
 *  - Sin cedula pero con telefono, el telefono hace de identificador. Es como
 *    un restaurante reconoce de verdad a quien vuelve a llamar.
 *  - Sin ninguno de los dos no se guarda nada: un tercero identificado solo
 *    por un nombre no se puede volver a encontrar sin duplicarlo.
 */
class DeliveryCustomerRegistrar
{
    public function register(
        int $companyId,
        string $nombre,
        ?string $documento = null,
        ?string $telefono = null,
        ?string $direccion = null,
        ?string $notasDireccion = null,
    ): ?ThirdParty {
        $documento = $this->limpiar($documento);
        $telefono = $this->limpiar($telefono);
        $nombre = trim($nombre);

        $identificador = $documento ?: $telefono;

        if ($identificador === null || $nombre === '') {
            return null;
        }

        // Sin cedula el identificador es el telefono, y queda marcado: ese
        // numero no es un documento de identidad y no puede viajar a la DIAN
        // como si lo fuera.
        $sinCedula = $documento === null;

        $cliente = ThirdParty::query()->firstOrNew([
            'company_id' => $companyId,
            'document_type' => 'cc',
            'document_number' => $identificador,
        ]);

        // En un cliente que ya existe no se pisa lo que tenga con lo que venga
        // vacio: el domicilio se toma deprisa y es facil dejar un campo en
        // blanco. Solo se actualiza con datos nuevos.
        $cliente->fill([
            'person_type' => $cliente->person_type ?: 'natural',
            'name' => $nombre ?: $cliente->name,
            'phone' => $telefono ?: $cliente->phone,
            'address' => trim((string) $direccion) ?: $cliente->address,
            'is_customer' => true,
            'active' => true,
        ]);

        // Solo se marca al crearlo. Si mas adelante alguien le registra la
        // cedula de verdad, no se vuelve a degradar.
        if (! $cliente->exists) {
            $cliente->is_delivery_contact = $sinCedula;
        }

        if ($notasDireccion = trim((string) $notasDireccion)) {
            $cliente->notes = $notasDireccion;
        }

        $cliente->save();

        return $cliente;
    }

    /**
     * Busca clientes por nombre, documento o telefono.
     *
     * @return Collection<int, ThirdParty>
     */
    public function search(int $companyId, string $termino, int $limite = 8): Collection
    {
        $termino = trim($termino);

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
                    ->orWhere('phone', 'like', "%{$termino}%")
                    ->orWhere('mobile', 'like', "%{$termino}%");
            })
            ->orderBy('name')
            ->limit($limite)
            ->get();
    }

    /** Un identificador vacio no sirve para volver a encontrar a nadie. */
    protected function limpiar(?string $valor): ?string
    {
        $valor = preg_replace('/\s+/', '', (string) $valor);

        return $valor === '' ? null : $valor;
    }
}
