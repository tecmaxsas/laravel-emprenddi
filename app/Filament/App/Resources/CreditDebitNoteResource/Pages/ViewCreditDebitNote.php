<?php

namespace App\Filament\App\Resources\CreditDebitNoteResource\Pages;

use App\Filament\App\Resources\CreditDebitNoteResource;
use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\CreditDebitNote;
use App\Services\Dian\CreditDebitNoteSender;
use App\Services\Sales\CreditDebitNoteEngine;
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
                        Notification::make()->danger()->title('Error')->body($e->getMessage())->persistent()->send();
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
                        Notification::make()->danger()->title('Error')->body($e->getMessage())->persistent()->send();
                    }
                }),
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
                        ->formatStateUsing(fn (string $s) => CreditDebitNote::TYPES[$s] ?? $s)
                        ->badge()
                        ->color(fn (string $s) => $s === 'credit' ? 'warning' : 'info'),
                    Infolists\Components\TextEntry::make('full_number')
                        ->label('Número')
                        ->state(fn (CreditDebitNote $r) => $r->fullNumber())
                        ->fontFamily('mono')->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $s) => CreditDebitNote::STATUSES[$s] ?? $s)
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
                        ->formatStateUsing(fn (?string $s) => $s ? (CreditDebitNote::DIAN_STATUSES[$s] ?? $s) : '—')
                        ->badge()
                        ->color(fn (?string $s) => match ($s) {
                            'accepted' => 'success', 'sent' => 'info',
                            'pending' => 'gray', 'rejected' => 'danger',
                            default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('dian_status_code')->label('Código DIAN')->placeholder('—'),
                    Infolists\Components\TextEntry::make('dian_sent_at')->label('Último envío')->dateTime('Y-m-d H:i:s')->placeholder('—'),
                    Infolists\Components\TextEntry::make('cufe')->label('CUFE')->columnSpan(3)->fontFamily('mono')->placeholder('—')->copyable(),
                    Infolists\Components\TextEntry::make('qr_url')->label('QR DIAN')->columnSpan(3)->placeholder('—')
                        ->url(fn (CreditDebitNote $r) => $r->qr_url, true)
                        ->openUrlInNewTab(),
                    Infolists\Components\TextEntry::make('dian_error_message')
                        ->label('Mensaje de error')
                        ->columnSpan(3)
                        ->visible(fn (CreditDebitNote $r) => ! empty($r->dian_error_message))
                        ->color('danger'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (CreditDebitNote $r) => ! empty($r->notes))
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label(''),
                ]),
        ]);
    }
}
