<?php

namespace App\Filament\App\Resources\AppointmentResource\Pages;

use App\Filament\App\Pages\PosTerminal;
use App\Filament\App\Resources\AppointmentResource;
use App\Models\Appointment;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAppointment extends EditRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('attend')
                ->label('Atender y cobrar')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn () => $this->record->sale_invoice_id === null
                    && $this->record->isOpen()
                    && auth()->user()?->can('appointments.manage')
                    && auth()->user()?->can('pos.use'))
                ->requiresConfirmation()
                ->modalHeading('Atender y cobrar')
                ->modalDescription('Se marcará la cita como atendida y se abrirá el POS con el cliente y el servicio precargados.')
                ->modalSubmitActionLabel('Ir al POS')
                ->action(function () {
                    $this->record->update(['status' => Appointment::STATUS_ATTENDED]);

                    return redirect(PosTerminal::getUrl(['appointment' => $this->record->id]));
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
