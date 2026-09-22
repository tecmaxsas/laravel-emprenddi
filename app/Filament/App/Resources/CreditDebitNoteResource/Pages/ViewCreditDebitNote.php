<?php

namespace App\Filament\App\Resources\CreditDebitNoteResource\Pages;

use App\Filament\App\Resources\CreditDebitNoteResource;
use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\CreditDebitNote;
use App\Services\Dian\CreditDebitNoteSender;
use App\Services\Sales\CreditDebitNoteEngine;
use App\Support\ErrorDeAccion;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewCreditDebitNote extends ViewRecord
{
    protected static string $resource = CreditDebitNoteResource::class;

    public function getTitle(): string
    {
        return $this->record->typeLabel().' '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->visible(fn (CreditDebitNote $r) => $r->isDraft()),

            Actions\Action::make('post')
                ->label('Contabilizar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (CreditDebitNote $r) => $r->isDraft()
                    && auth()->user()?->can('credit_debit_notes.post'))
                ->requiresConfirmation()
                ->modalHeading(fn (CreditDebitNote $r) => 'Contabilizar '.$r->typeLabel())
                ->modalDescription(fn (CreditDebitNote $r) => sprintf(
                    'Se generará el asiento contable invertido respecto a la factura %s. %s',
                    $r->saleInvoice?->fullNumber() ?? '?',
                    $r->isCredit() && $r->affects_inventory ? 'Los productos volverán al inventario.' : '',
                ))
                ->modalSubmitActionLabel('Contabilizar')
                ->action(function (CreditDebitNote $r) {
                    try {
                        $note = app(CreditDebitNoteEngine::class)->post($r);
                        Notification::make()
                            ->success()
                            ->title('Contabilizada')
                            ->body("Asiento {$note->journalEntry?->fullNumber()} creado.")
                            ->send();
                        $this->refreshFormData(['status', 'journal_entry_id', 'prefix', 'number', 'dian_status']);
                    } catch (\Throwable $e) {
                        ErrorDeAccion::reportar($e, 'contabilizar la nota', ['nota_id' => $r->id]);
                    }
                }),

            Actions\Action::make('sendDian')
                ->label(fn (CreditDebitNote $r) => $r->dian_status === CreditDebitNote::DIAN_REJECTED ? 'Reenviar a DIAN' : 'Enviar a DIAN')
                ->icon('heroicon-o-paper-airplane')
                ->color(fn (CreditDebitNote $r) => $r->dian_status === CreditDebitNote::DIAN_REJECTED ? 'warning' : 'primary')
                ->visible(fn (CreditDebitNote $r) => $r->canResendToDian()
                    && auth()->user()?->can('credit_debit_notes.send_dian'))
                ->requiresConfirmation()
                ->modalHeading(fn (CreditDebitNote $r) => 'Enviar '.$r->typeLabel().' a DIAN')
                ->modalDescription('Se enviará al servicio apidian.emprenddi.com con referencia a la factura original.')
                ->modalSubmitActionLabel('Enviar')
                ->action(function (CreditDebitNote $r) {
                    try {
                        $result = app(CreditDebitNoteSender::class)->send($r);
                        if ($result['ok']) {
                            Notification::make()
                                ->success()
                                ->title('Aceptada por DIAN')
                                ->body('CUFE: '.substr((string) $result['cufe'], 0, 32).'…')
                                ->send();
                        } else {
                            Notification::make()
                                ->danger()
                                ->title('DIAN rechazó la nota')
                                ->body($result['message'])
                                ->persistent()
                                ->send();
                        }
                        $this->refreshFormData(['dian_status', 'dian_status_code', 'cufe', 'qr_url', 'dian_error_message']);
                    } catch (\Throwable $e) {
                        ErrorDeAccion::reportar($e, 'enviar la nota a la DIAN', [
                            'nota_id' => $r->id,
                            'numero' => $r->fullNumber(),
                            'resolucion_id' => $r->dian_resolution_id,
                        ]);
                    }
                }),

            // La DIAN firma el documento en el momento del envío y exige que esa
            // fecha sea la del documento (regla CAD09). Una nota contabilizada
            // hace días no pasa nunca, y desde la pantalla no había cómo
            // arreglarlo: la fecha solo se edita en borrador.
            Actions\Action::make('fecharHoy')
                ->label('Poner fecha de hoy')
                ->icon('heroicon-o-calendar-days')
                ->color('warning')
                ->visible(fn (CreditDebitNote $r) => $r->isPosted()
                    && ! $r->cufe
                    && $r->dian_status !== CreditDebitNote::DIAN_ACCEPTED
                    && $r->dian_status !== CreditDebitNote::DIAN_SENT
                    && ! $r->date?->isSameDay(now())
                    && auth()->user()?->can('credit_debit_notes.post'))
                ->requiresConfirmation()
                ->modalHeading('Emitir la nota con la fecha de hoy')
                ->modalDescription(fn (CreditDebitNote $r) => sprintf(
                    'Esta nota está fechada el %s. La DIAN exige que la fecha del documento '
                    .'sea la misma en que se firma —hoy, %s—, así que con la fecha actual el '
                    .'envío se rechaza siempre. La nota y su asiento contable pasan a hoy.',
                    $r->date?->format('Y-m-d') ?? '—',
                    now()->format('Y-m-d'),
                ))
                ->modalSubmitActionLabel('Poner fecha de hoy')
                ->action(function (CreditDebitNote $r) {
                    try {
                        $nota = app(CreditDebitNoteEngine::class)->ponerFechaDeHoy($r);

                        Notification::make()
                            ->success()
                            ->title('Nota fechada hoy')
                            ->body("Ahora es del {$nota->date->format('Y-m-d')}. Ya se puede enviar a la DIAN.")
                            ->send();

                        $this->refreshFormData(['date', 'dian_status', 'dian_error_message']);
                    } catch (\Throwable $e) {
                        ErrorDeAccion::reportar($e, 'cambiar la fecha de la nota',
                            ['nota_id' => $r->id], titulo: 'No se pudo cambiar la fecha');
                    }
                }),

            // Rescate de dos situaciones que terminan igual —una nota que no va a
            // pasar nunca con el número que tiene— y que antes obligaban a
            // anularla y rehacerla, con lo que eso le hace a la cuenta del
            // cliente: la que se contabilizó sin resolución mientras el motor la
            // buscaba por la sede, y la que la DIAN rechazó porque su consecutivo
            // ya estaba ocupado allá.
            Actions\Action::make('assignResolution')
                ->label(fn (CreditDebitNote $r) => $r->dian_resolution_id
                    ? 'Renumerar y reintentar'
                    : 'Asignar resolución DIAN')
                ->icon('heroicon-o-hashtag')
                ->color('warning')
                ->visible(fn (CreditDebitNote $r) => $r->isPosted()
                    && ! $r->cufe
                    && $r->dian_status !== CreditDebitNote::DIAN_ACCEPTED
                    && $r->dian_status !== CreditDebitNote::DIAN_SENT
                    && auth()->user()?->can('credit_debit_notes.post'))
                ->requiresConfirmation()
                ->modalHeading('Numerar con la resolución de la empresa')
                ->modalDescription(function (CreditDebitNote $r) {
                    $documento = $r->isCredit() ? 'nota crédito' : 'nota débito';

                    if (! $r->dian_resolution_id) {
                        return sprintf(
                            'Esta nota se numeró a mano (%s) y por eso la DIAN la rechaza: el '
                            .'documento no tiene resolución. Se le va a asignar el siguiente '
                            .'consecutivo de la resolución de %s de la empresa, y el asiento '
                            .'contable se actualiza con el número nuevo. Después podrás enviarla.',
                            $r->fullNumber(),
                            $documento,
                        );
                    }

                    if ($r->dian_status === CreditDebitNote::DIAN_REJECTED) {
                        return sprintf(
                            'La DIAN rechazó esta nota con el número %s%s Se le va a asignar el '
                            .'siguiente consecutivo libre de la resolución de %s de la empresa, y '
                            .'el asiento contable se actualiza con el número nuevo. Después podrás '
                            .'reintentar el envío.',
                            $r->fullNumber(),
                            $r->dian_error_message
                                ? ': «'.mb_strimwidth((string) $r->dian_error_message, 0, 120, '…').'».'
                                : '.',
                            $documento,
                        );
                    }

                    return sprintf(
                        'Esta nota tiene el número %s y todavía no se ha enviado. Se le va a '
                        .'asignar el siguiente consecutivo libre de la resolución de %s de la '
                        .'empresa, y el asiento contable se actualiza con él.',
                        $r->fullNumber(),
                        $documento,
                    );
                })
                ->modalSubmitActionLabel('Asignar y renumerar')
                ->action(function (CreditDebitNote $r) {
                    try {
                        $numeroAnterior = $r->fullNumber();
                        $nota = app(CreditDebitNoteEngine::class)->asignarResolucionDian($r);

                        // Que el número no se mueva es un resultado posible y
                        // desconcertante: el siguiente libre resultó ser el que ya
                        // tenía. Decir «renumerada» ahí manda al usuario a
                        // reintentar un envío que va a fallar por lo mismo.
                        if ($nota->fullNumber() === $numeroAnterior) {
                            Notification::make()
                                ->warning()
                                ->title('La nota sigue siendo '.$nota->fullNumber())
                                ->body('El siguiente consecutivo libre de la resolución es el que ya '
                                    .'tenía. Si la DIAN la rechazó porque ese número ya está emitido '
                                    .'allá, hay que mover el consecutivo de la resolución antes de '
                                    .'volver a intentarlo.')
                                ->persistent()
                                ->send();

                            $this->refreshFormData(['prefix', 'number', 'dian_resolution_id', 'dian_status', 'dian_error_message']);

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Nota renumerada')
                            ->body("Ahora es {$nota->fullNumber()}. Ya se puede enviar a la DIAN.")
                            ->send();

                        $this->refreshFormData(['prefix', 'number', 'dian_resolution_id', 'dian_status', 'dian_error_message']);
                    } catch (\Throwable $e) {
                        ErrorDeAccion::reportar($e, 'asignar resolución a la nota',
                            ['nota_id' => $r->id], titulo: 'No se pudo asignar');
                    }
                }),

            // El PDF con CUFE y QR lo genera el proveedor al autorizar la DIAN;
            // uno hecho aquí se parecería pero no sería el documento válido.
            Actions\Action::make('downloadPdf')
                ->label('Descargar PDF DIAN')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (CreditDebitNote $r) => $r->dian_status === CreditDebitNote::DIAN_ACCEPTED
                    && auth()->user()?->can('credit_debit_notes.view'))
                ->url(fn (CreditDebitNote $r) => route('dian.credit_debit_note.pdf', ['note' => $r->id]))
                ->openUrlInNewTab(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('type')
                        ->label('Tipo')
                        ->formatStateUsing(fn (string $state) => CreditDebitNote::TYPES[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => $state === 'credit' ? 'warning' : 'info'),
                    Infolists\Components\TextEntry::make('full_number')
                        ->label('Número')
                        ->state(fn (CreditDebitNote $r) => $r->fullNumber())
                        ->fontFamily('mono')->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $state) => CreditDebitNote::STATUSES[$state] ?? $state)
                        ->badge(),
                    Infolists\Components\TextEntry::make('saleInvoice.full_number')
                        ->label('Factura referenciada')
                        ->state(fn (CreditDebitNote $r) => $r->saleInvoice?->fullNumber())
                        ->url(fn (CreditDebitNote $r) => $r->saleInvoice
                            ? SaleInvoiceResource::getUrl('view', ['record' => $r->saleInvoice])
                            : null)
                        ->fontFamily('mono'),
                    Infolists\Components\TextEntry::make('customer.name')->label('Cliente')->columnSpan(2),
                    Infolists\Components\TextEntry::make('reason_code')
                        ->label('Motivo DIAN')
                        ->state(fn (CreditDebitNote $r) => $r->reasonLabel()),
                ]),

            Infolists\Components\Section::make('Líneas')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('lines')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('product.code')->label('SKU')->fontFamily('mono')->placeholder('—')->columnSpan(2),
                            Infolists\Components\TextEntry::make('description')->label('Descripción')->columnSpan(4),
                            Infolists\Components\TextEntry::make('quantity')->label('Cant.')->numeric(decimalPlaces: 2)->columnSpan(1),
                            Infolists\Components\TextEntry::make('unit_price')->label('Precio')->money('COP')->columnSpan(2),
                            Infolists\Components\TextEntry::make('tax.code')->label('Imp.')->placeholder('—')->columnSpan(1),
                            Infolists\Components\TextEntry::make('total')->label('Total')->money('COP')->weight('semibold')->columnSpan(2),
                        ])
                        ->columns(12),
                ]),

            Infolists\Components\Section::make('Totales')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('COP'),
                    Infolists\Components\TextEntry::make('discount_total')->label('Descuento')->money('COP'),
                    Infolists\Components\TextEntry::make('tax_total')->label('IVA')->money('COP'),
                    Infolists\Components\TextEntry::make('total')->label('TOTAL')->money('COP')->weight('bold'),
                ]),

            Infolists\Components\Section::make('Asiento contable')
                ->visible(fn (CreditDebitNote $r) => $r->journal_entry_id !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('journalEntry.full_number')
                        ->label('Asiento')
                        ->state(fn (CreditDebitNote $r) => $r->journalEntry?->fullNumber())
                        ->url(fn (CreditDebitNote $r) => $r->journalEntry
                            ? route('filament.app.resources.journal-entries.view', $r->journalEntry)
                            : null),
                ]),

            Infolists\Components\Section::make('Facturación electrónica DIAN')
                ->visible(fn (CreditDebitNote $r) => $r->dian_status !== null)
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('dian_status')
                        ->label('Estado')
                        ->formatStateUsing(fn (?string $state) => $state ? (CreditDebitNote::DIAN_STATUSES[$state] ?? $state) : '—')
                        ->badge()
                        ->color(fn (?string $state) => match ($state) {
                            'accepted' => 'success', 'sent' => 'info',
                            'pending' => 'gray', 'rejected' => 'danger',
                            default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('dian_status_code')->label('Código DIAN')->placeholder('—'),
                    Infolists\Components\TextEntry::make('dian_sent_at')->label('Último envío')->dateTime('Y-m-d H:i:s')->placeholder('—'),
                    // En notas la DIAN lo llama CUDE. La columna sigue siendo
                    // `cufe` porque renombrarla no vale una migración, pero el
                    // usuario tiene que ver el nombre que le van a pedir.
                    Infolists\Components\TextEntry::make('cufe')->label('CUDE')->columnSpan(3)->fontFamily('mono')->placeholder('—')->copyable(),
                    Infolists\Components\TextEntry::make('qr_url')->label('QR DIAN')->columnSpan(3)->placeholder('—')
                        ->url(fn (CreditDebitNote $r) => $r->qr_url, true)
                        ->openUrlInNewTab(),
                    // Una nota aceptada también trae mensajes: notificaciones que
                    // la DIAN deja para corregir de cara al próximo documento.
                    // Pintarlas de rojo bajo el rótulo «error» hace que se lea
                    // como un rechazo lo que no lo es.
                    Infolists\Components\TextEntry::make('dian_error_message')
                        ->label(fn (CreditDebitNote $r) => $r->isDianAccepted()
                            ? 'Notificaciones de la DIAN'
                            : 'Mensaje de error')
                        ->columnSpan(3)
                        ->visible(fn (CreditDebitNote $r) => ! empty($r->dian_error_message))
                        ->color(fn (CreditDebitNote $r) => $r->isDianAccepted() ? 'warning' : 'danger'),
                ]),

            // El rechazo de la DIAN («la resolución no está configurada») no le
            // dice a nadie qué hacer. Esto sí, y aparece antes de intentarlo.
            Infolists\Components\Section::make('Sin resolución DIAN')
                ->visible(fn (CreditDebitNote $r) => $r->isPosted() && ! $r->dian_resolution_id)
                ->schema([
                    Infolists\Components\TextEntry::make('sin_resolucion')
                        ->label('')
                        ->color('warning')
                        ->state(fn (CreditDebitNote $r) => sprintf(
                            'Esta nota se numeró a mano (%s) porque la empresa no tenía resolución '
                            .'de %s cuando se contabilizó. Así no se puede enviar a la DIAN. '
                            .'Carga la resolución en Configuración → DIAN (no hay que asignarla a '
                            .'ninguna sede: la numeración de notas es de toda la empresa) y usa el '
                            .'botón «Asignar resolución DIAN» de arriba.',
                            $r->fullNumber(),
                            $r->isCredit() ? 'nota crédito' : 'nota débito',
                        )),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (CreditDebitNote $r) => ! empty($r->notes))
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label(''),
                ]),
        ]);
    }
}
