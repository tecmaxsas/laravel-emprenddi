<?php

namespace App\Filament\App\Resources\Hotel\ReservationGroupResource\Pages;

use App\Filament\App\Resources\Hotel\ReservationGroupResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Editar toca los datos del bloque —nombre, contacto, quien paga— pero NO sus
 * fechas ni sus habitaciones: mover eso es reapartar, y para eso estan las
 * acciones de agregar habitaciones y de cancelar.
 */
class EditReservationGroup extends EditRecord
{
    protected static string $resource = ReservationGroupResource::class;
}
