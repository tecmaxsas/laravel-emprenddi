<?php

namespace App\Filament\App\Resources\Hotel\ReservationResource\Pages;

use App\Filament\App\Resources\Hotel\ReservationResource;
use App\Models\Hotel\Reservation;
use App\Services\Hotel\HotelReservationEngine;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Igual que al crear: el motor es el que decide si las fechas nuevas caben.
 * Mover una reserva dos noches adelante puede chocar con la que entra después.
 */
class EditReservation extends EditRecord
{
    protected static string $resource = ReservationResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Reservation $record */
        $motor = app(HotelReservationEngine::class);

        try {
            $reserva = $motor->modificar($record, $data);

            // La habitación se mueve aparte porque asignarla y liberarla
            // tienen cada una su propia comprobación.
            $nueva = $data['room_id'] ?? null;

            if ((int) $nueva !== (int) $reserva->room_id) {
                $reserva = $nueva
                    ? $motor->asignarHabitacion($reserva, (int) $nueva)
                    : $motor->liberarHabitacion($reserva);
            }

            return $reserva;
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['data.room_id' => $e->getMessage()]);
        }
    }
}
