<?php

namespace App\Filament\App\Resources\Hotel\ReservationResource\Pages;

use App\Filament\App\Resources\Hotel\ReservationResource;
use App\Services\Hotel\HotelReservationEngine;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Guardar pasa por el motor, no por `Model::create`.
 *
 * El motor es el que consulta disponibilidad antes de prometer una habitación.
 * Si este formulario escribiera directo al modelo, el sobrecupo volvería por
 * la puerta de atrás: la pantalla muestra lo que queda libre, pero entre que
 * se abre y se guarda puede entrar otra reserva.
 */
class CreateReservation extends CreateRecord
{
    protected static string $resource = ReservationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(HotelReservationEngine::class)->crear($data);
        } catch (\RuntimeException $e) {
            // Como error de validación y no como excepción suelta: así queda
            // sobre el formulario con los datos puestos, en vez de mandar al
            // recepcionista a una pantalla de error con el cliente al frente.
            throw ValidationException::withMessages(['data.room_id' => $e->getMessage()]);
        }
    }
}
