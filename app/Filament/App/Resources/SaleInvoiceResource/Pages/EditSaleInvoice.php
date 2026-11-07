<?php

namespace App\Filament\App\Resources\SaleInvoiceResource\Pages;

use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\SaleInvoice;
use App\Services\Sales\SaleInvoiceEngine;
use App\Support\PosSettings;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditSaleInvoice extends EditRecord
{
    protected static string $resource = SaleInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->guardarYContabilizarAction(),

            Actions\DeleteAction::make()
                ->visible(fn (SaleInvoice $record) => $record->status === 'draft'),
        ];
    }

    /**
     * Guardar y contabilizar de una sola vez.
     *
     * Corregir una factura eran cuatro pasos: guardar, volver a la ficha,
     * buscar «Contabilizar», confirmar. Y entre el primero y el segundo no
     * pasaba nada visible, asi que la factura se quedaba en borrador sin que
     * nadie lo notara —en borrador no descarga inventario ni genera asiento,
     * de modo que el error se descubre cuando las cifras ya no cuadran—.
     *
     * Sigue pidiendo confirmacion: contabilizar genera el asiento, descarga
     * inventario y quema el consecutivo de la DIAN. Lo que se ahorra son los
     * pasos, no la decision.
     */
    protected function guardarYContabilizarAction(): Actions\Action
    {
        return Actions\Action::make('guardarYContabilizar')
            ->label('Guardar y contabilizar')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (SaleInvoice $record) => $record->status === 'draft'
                && auth()->user()?->can('sales.post'))
            ->requiresConfirmation()
            ->modalHeading('Guardar y contabilizar la factura')
            ->modalDescription('Se guardan los cambios y la factura queda contabilizada: se genera '
                .'el asiento, sale el inventario y se reserva el consecutivo. Después no se puede '
                .'editar sin devolverla a borrador.')
            ->modalSubmitActionLabel('Guardar y contabilizar')
            ->action(function () {
                // Primero guardar, sin redirigir: si el formulario no valida,
                // aqui se corta y no se contabiliza nada a medias.
                $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                try {
                    $factura = app(SaleInvoiceEngine::class)->post(
                        $this->record->fresh(['lines']),
                        allowNegativeStock: PosSettings::allowsNegativeStock(),
                    );

                    Notification::make()
                        ->success()
                        ->title('Factura contabilizada — '.$factura->fullNumber())
                        ->body($factura->journalEntry
                            ? "Asiento {$factura->journalEntry->fullNumber()} creado."
                            : 'Guardada y contabilizada.')
                        ->send();

                    $this->redirect(SaleInvoiceResource::getUrl('index'));
                } catch (\Throwable $e) {
                    // Los cambios quedaron guardados: solo falló contabilizar.
                    // Se dice, porque si no el usuario no sabe si perdió lo que
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
     * Al guardar, llevar a la ficha de la factura.
     *
     * Es donde esta el boton de contabilizar. Antes el guardado dejaba al
     * usuario en el mismo formulario, sin señal de que faltaba un paso.
     */
    protected function getRedirectUrl(): string
    {
        return SaleInvoiceResource::getUrl('view', ['record' => $this->record]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'No se puede editar una factura contabilizada.',
            ]);
        }

        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;
        $lineNum = 1;

        $data['lines'] = collect($data['lines'] ?? [])->map(function ($line) use (&$lineNum, &$subtotal, &$discount, &$tax, &$total) {
            $line['line_number'] = $lineNum++;
            $subtotal += (float) ($line['subtotal'] ?? 0);
            $discount += (float) ($line['discount_amount'] ?? 0);
            $tax += (float) ($line['tax_amount'] ?? 0);
            $total += (float) ($line['total'] ?? 0);

            return $line;
        })->all();

        $retentionTotal = collect($data['retentions'] ?? [])
            ->sum(fn ($r) => (float) ($r['amount'] ?? 0));

        $data['subtotal'] = $subtotal;
        $data['discount_total'] = $discount;
        $data['tax_total'] = $tax;
        $data['total'] = $total;
        $data['retention_total'] = $retentionTotal;
        $data['net_payable'] = $total - $retentionTotal;

        return $data;
    }
}
