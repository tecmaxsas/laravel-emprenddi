<?php

namespace App\Support\Dian;

use App\Models\SaleInvoice;
use App\Services\Dian\DianEmailResender;
use App\Services\Dian\DianStatusChecker;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * Logica compartida de las acciones DIAN sobre una factura (reenvio de correo
 * y consulta de estado).
 *
 * Vive aparte porque las mismas dos acciones se ofrecen en tres lugares con
 * clases distintas de Filament: la vista de la factura (Actions\Action), el
 * listado (Tables\Actions\Action) y la seleccion multiple (BulkAction). El
 * formulario y el manejo del resultado se definen una sola vez aqui; cada
 * lugar solo arma su boton.
 */
class DianInvoiceActions
{
    /**
     * Una factura solo tiene gestion DIAN si es electronica y ya viajo:
     * sin CUFE no hay nada que consultar ni que reenviar.
     */
    public static function isManageable(?SaleInvoice $invoice): bool
    {
        return $invoice !== null
            && ! $invoice->isPosInvoice()
            && ! empty($invoice->cufe);
    }

    /** @return array<int, Forms\Components\Component> */
    public static function resendEmailForm(): array
    {
        return [
            Forms\Components\TextInput::make('alternate_email')
                ->label('Enviar a')
                ->email()
                ->required()
                ->maxLength(150)
                ->helperText('Se envían el PDF y el XML de la factura electrónica a este correo.'),

            Forms\Components\Repeater::make('cc')
                ->label('Copias')
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->label('Correo')
                        ->email()
                        ->required()
                        ->maxLength(150),
                ])
                ->addActionLabel('Agregar copia')
                ->collapsed()
                ->itemLabel(fn (array $state) => $state['email'] ?? 'Nuevo correo'),

            Forms\Components\Toggle::make('cc_as_cc')
                ->label('Enviar las copias como CC visible')
                ->helperText('Apagado, cada copia recibe el correo como destinatario independiente.'),
        ];
    }

    /**
     * Valores iniciales del formulario. Va por fillForm() y no por default()
     * en cada campo: es la via documentada que recibe el $record de la accion,
     * tanto en la vista como en el listado.
     */
    public static function resendEmailDefaults(SaleInvoice $invoice): array
    {
        return [
            'alternate_email' => $invoice->customer?->email,
            'cc' => [],
            'cc_as_cc' => true,
        ];
    }

    /**
     * Ejecuta el reenvio y notifica el resultado. No lanza excepciones: los
     * errores se muestran al usuario.
     */
    public static function resendEmail(SaleInvoice $invoice, array $data): void
    {
        try {
            $result = app(DianEmailResender::class)->resend($invoice, [
                'alternate_email' => $data['alternate_email'] ?? '',
                'cc' => array_column($data['cc'] ?? [], 'email'),
                'cc_as_cc' => (bool) ($data['cc_as_cc'] ?? true),
            ]);
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('No se pudo reenviar')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        if (! $result['ok']) {
            Notification::make()
                ->danger()
                ->title('No se pudo reenviar')
                ->body($result['message'])
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Correo reenviado')
            ->body(trim(sprintf(
                'Factura %s enviada a %s.%s',
                $invoice->fullNumber(),
                $data['alternate_email'] ?? '',
                $result['outgoing_mail'] ? ' Remitente: '.$result['outgoing_mail'].'.' : '',
            )))
            ->send();
    }

    /**
     * Consulta el estado en DIAN y notifica. Devuelve el resultado para que
     * quien llame (por ejemplo la accion masiva) pueda contar.
     *
     * @return array{ok:bool, changed:bool, status:?string, message:string}
     */
    public static function checkStatus(SaleInvoice $invoice, bool $notify = true): array
    {
        try {
            $result = app(DianStatusChecker::class)->check($invoice);
        } catch (\Throwable $e) {
            if ($notify) {
                Notification::make()
                    ->danger()
                    ->title('No se pudo consultar el estado')
                    ->body($e->getMessage())
                    ->persistent()
                    ->send();
            }

            return ['ok' => false, 'changed' => false, 'status' => null, 'message' => $e->getMessage()];
        }

        if ($notify) {
            $status = $result['status'];

            $notification = Notification::make()
                ->title(match ($status) {
                    SaleInvoice::DIAN_ACCEPTED => 'Autorizada por la DIAN',
                    SaleInvoice::DIAN_REJECTED => 'Rechazada por la DIAN',
                    SaleInvoice::DIAN_SENT => 'En validación',
                    default => 'Consulta de estado',
                })
                ->body($result['message'].($result['changed'] ? ' El estado guardado se actualizó.' : ''));

            match (true) {
                ! $result['ok'] => $notification->warning()->persistent(),
                $status === SaleInvoice::DIAN_ACCEPTED => $notification->success(),
                $status === SaleInvoice::DIAN_REJECTED => $notification->danger()->persistent(),
                default => $notification->info(),
            };

            $notification->send();
        }

        return $result;
    }
}
