<?php

namespace App\Filament\Concerns;

use App\Models\Payment;
use App\Services\Sales\PaymentDeleter;
use App\Services\Sales\PaymentEditor;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Support\HtmlString;

/**
 * Corregir y borrar un cobro, desde la factura que lo recibio.
 *
 * Las dos pantallas de pagos —la de ventas y la de compras— necesitan los
 * mismos dos botones, con las mismas guardas y las mismas explicaciones. Ya
 * habian empezado a divergir: el boton de borrar estaba copiado entero en las
 * dos y el de corregir solo existia en el estado de cuenta, con su propia
 * version de la regla.
 *
 * Lo unico que cambia entre las dos es el permiso que las gobierna, asi que
 * entra por parametro. Las reglas de verdad —que se puede, que arrastra, que
 * pasa— viven en PaymentDeleter y PaymentEditor; aqui solo estan los botones.
 *
 * Quien use el trait tiene que ofrecer `paymentFormSchema(?Payment $corrigiendo)`
 * y `getOwnerRecord()`.
 */
trait AccionesDePago
{
    /**
     * Lo maximo que puede valer este cobro.
     *
     * Al corregir, el tope es el saldo pendiente MAS el cobro que se esta
     * corrigiendo: esa plata va a volver a la factura cuando se reemplace. Sin
     * sumarla, el formulario diria que no se puede subir el valor de un cobro
     * que ya cubria todo el saldo, cuando si se puede.
     */
    protected function topeDelPago(?Payment $corrigiendo = null): float
    {
        return round(
            (float) $this->getOwnerRecord()->balance + (float) ($corrigiendo?->amount ?? 0),
            2,
        );
    }

    /**
     * Corregir un cobro mal registrado.
     *
     * @param  string  $permiso  sales.receive_payment o purchases.pay
     */
    protected function accionCorregirPago(string $permiso): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('editPayment')
            ->label('Corregir')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn () => auth()->user()?->can($permiso))
            ->disabled(fn (Payment $record) => app(PaymentEditor::class)
                ->motivoParaNoEditar($record) !== null)
            // El motivo en el tooltip: un boton gris sin explicacion deja al
            // usuario adivinando por que no puede.
            ->tooltip(fn (Payment $record) => app(PaymentEditor::class)
                ->motivoParaNoEditar($record))
            ->modalHeading('Corregir este cobro')
            ->modalDescription(new HtmlString(
                '<div style="font-size:13px;line-height:1.6;">'
                .'<p>El cobro no se modifica: <strong>se reemplaza</strong>. El actual queda '
                .'borrado y su asiento reversado, y estos datos entran como un cobro nuevo. '
                .'Así la factura, la contabilidad y el arqueo de caja quedan contando lo mismo.</p>'
                .'</div>'
            ))
            ->modalSubmitActionLabel('Guardar la corrección')
            ->fillForm(fn (Payment $record) => [
                'date' => $record->date?->toDateString(),
                'amount' => (float) $record->amount,
                'payment_method' => $record->payment_method,
                'account_id' => $record->account_id,
                'reference' => $record->reference,
                'description' => $record->description,
            ])
            ->form(fn (Payment $record) => $this->paymentFormSchema($record))
            ->action(function (Payment $record, array $data) {
                try {
                    $nuevo = app(PaymentEditor::class)->replace($record, $data);

                    Notification::make()->success()
                        ->title('Cobro corregido')
                        ->body('Quedó en $'.number_format((float) $nuevo->amount, 0, ',', '.')
                            .'. El saldo de la factura y la caja se actualizaron.')
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()
                        ->title('No se pudo corregir el cobro')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Borrar un cobro registrado por error.
     *
     * Un cobro mal digitado —el monto equivocado, el metodo equivocado, o dos
     * veces el mismo— solo se podia deshacer borrando la factura entera.
     *
     * @param  string  $permiso  sales.receive_payment o purchases.pay
     */
    protected function accionBorrarPago(string $permiso): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('deletePayment')
            ->label('Borrar')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn () => auth()->user()?->can($permiso))
            ->disabled(fn (Payment $record) => app(PaymentDeleter::class)
                ->motivoParaNoBorrar($record) !== null)
            ->tooltip(fn (Payment $record) => app(PaymentDeleter::class)
                ->motivoParaNoBorrar($record))
            ->requiresConfirmation()
            ->modalHeading('Borrar este cobro')
            ->modalDescription(fn (Payment $record) => new HtmlString(
                '<div style="font-size:13px;line-height:1.6;">'
                .'<p style="margin-bottom:8px;">Esto es lo que va a pasar:</p><ul style="margin-left:18px;list-style:disc;">'
                .implode('', array_map(
                    fn (string $c) => '<li>'.e($c).'</li>',
                    app(PaymentDeleter::class)->consecuencias($record),
                ))
                .'</ul></div>'
            ))
            ->modalSubmitActionLabel('Sí, borrar el cobro')
            ->form([
                Forms\Components\TextInput::make('motivo')
                    ->label('Motivo')
                    ->placeholder('Ej. Se registró dos veces')
                    ->helperText('Queda guardado en el pago para que después se sepa por qué se borró.')
                    ->maxLength(200),
            ])
            ->action(function (Payment $record, array $data) {
                try {
                    app(PaymentDeleter::class)->delete($record, $data['motivo'] ?? null);

                    Notification::make()->success()
                        ->title('Cobro borrado')
                        ->body('El saldo de la factura y la caja se actualizaron.')
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()
                        ->title('No se pudo borrar el cobro')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }
}
