<?php

namespace App\Filament\App\Resources\PurchaseInvoiceResource\Pages;

use App\Filament\App\Resources\PurchaseInvoiceResource;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\PurchaseInvoiceEngine;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditPurchaseInvoice extends EditRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->guardarYContabilizarAction(),

            Actions\DeleteAction::make()
                ->visible(fn (PurchaseInvoice $record) => $record->status === 'draft'),
        ];
    }

    /**
     * Guardar y contabilizar de una sola vez.
     *
     * Corregir una compra eran cuatro pasos: guardar, volver a la ficha,
     * buscar «Contabilizar», confirmar. Y entre el primero y el segundo no
     * pasaba nada visible, asi que la compra se quedaba en borrador sin que
     * nadie lo notara —en borrador no entra inventario ni genera asiento, de
     * modo que el error se descubre cuando las cifras ya no cuadran—.
     *
     * Ventas ya lo tenia; compras no, y es el mismo recorrido.
     */
    protected function guardarYContabilizarAction(): Actions\Action
    {
        return Actions\Action::make('guardarYContabilizar')
            ->label('Guardar y contabilizar')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (PurchaseInvoice $record) => $record->status === 'draft'
                && auth()->user()?->can('purchases.post'))
            ->requiresConfirmation()
            ->modalHeading('Guardar y contabilizar la compra')
            ->modalDescription('Se guardan los cambios y la compra queda contabilizada: se genera '
                .'el asiento y entra el inventario. Después no se puede editar sin devolverla '
                .'a borrador.')
            ->modalSubmitActionLabel('Guardar y contabilizar')
            ->action(function () {
                // Primero guardar, sin redirigir: si el formulario no valida,
                // aqui se corta y no se contabiliza nada a medias.
                $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                try {
                    $factura = app(PurchaseInvoiceEngine::class)->post($this->record->fresh(['lines']));

                    Notification::make()
                        ->success()
                        ->title('Compra contabilizada — '.$factura->fullNumber())
                        ->body($factura->journalEntry
                            ? "Asiento {$factura->journalEntry->fullNumber()} creado."
                            : 'Guardada y contabilizada.')
                        ->send();

                    $this->redirect(PurchaseInvoiceResource::getUrl('index'));
                } catch (\Throwable $e) {
                    // Los cambios quedaron guardados: solo fallo contabilizar.
                    // Se dice, porque si no el usuario no sabe si perdio lo que
                    // acababa de escribir.
                    Notification::make()
                        ->danger()
                        ->title('Se guardó, pero no se pudo contabilizar')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Al guardar, llevar a la ficha de la compra.
     *
     * Es donde esta el boton de contabilizar. Antes el guardado dejaba al
     * usuario en el mismo formulario, sin señal de que faltaba un paso.
     */
    protected function getRedirectUrl(): string
    {
        return PurchaseInvoiceResource::getUrl('view', ['record' => $this->record]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'No se puede editar una factura contabilizada.',
            ]);
        }

        // Los totales NO se calculan aqui.
        //
        // El repetidor de lineas usa ->relationship('lines'), y Filament saca
        // esos campos del arreglo antes de guardar para persistirlos aparte.
        // O sea que `$data['lines']` llega VACIO: la suma recorria la nada y
        // escribia ceros. Editar una factura la dejaba en $0 con sus lineas
        // intactas —y al volver a contabilizarla, con un asiento de cero—.
        //
        // Se recalculan despues, desde las lineas ya guardadas, con el mismo
        // metodo del motor que usa post(). Una sola version de la suma.

        return $data;
    }

    protected function afterSave(): void
    {
        app(PurchaseInvoiceEngine::class)->recalculateTotals($this->record);
    }
}
