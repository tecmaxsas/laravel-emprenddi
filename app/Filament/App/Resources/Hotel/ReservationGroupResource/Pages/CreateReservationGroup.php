<?php

namespace App\Filament\App\Resources\Hotel\ReservationGroupResource\Pages;

use App\Filament\App\Resources\Hotel\ReservationGroupResource;
use App\Services\Hotel\HotelGroupEngine;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Crear el bloque pasa por el motor, no por `Model::create`.
 *
 * El motor aparta cada habitación llamando al de reservas, que pregunta a
 * HotelAvailability. Escribiendo directo al modelo, un grupo seria la forma
 * mas rapida de meter un sobrecupo de diez habitaciones de un golpe.
 */
class CreateReservationGroup extends CreateRecord
{
    protected static string $resource = ReservationGroupResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $resultado = app(HotelGroupEngine::class)->crear($data);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['data.name' => $e->getMessage()]);
        }

        // Si no alcanzaron todas, se dice cuantas faltaron en vez de dejar al
        // recepcionista creyendo que aparto diez.
        $aviso = $resultado['faltaron'] !== []
            ? 'Ojo: '.implode(' · ', $resultado['faltaron'])
            : null;

        Notification::make()
            ->title($resultado['creadas'].' habitación(es) apartadas')
            ->body($aviso)
            ->{$aviso ? 'warning' : 'success'}()
            ->persistent((bool) $aviso)
            ->send();

        return $resultado['grupo'];
    }
}
