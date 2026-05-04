<?php

namespace App\Filament\App\Resources\SaleInvoiceResource\Pages;

use App\Filament\App\Resources\CreditDebitNoteResource;
use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\SaleInvoice;
use App\Services\Dian\DianInvoiceSender;
use App\Services\Sales\CreditDebitNoteNumberer;
use App\Services\Sales\SaleInvoiceEngine;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewSaleInvoice extends ViewRecord
{
    protected static string $resource = SaleInvoiceResource::class;

    public function getTitle(): string
    {
        return 'Factura '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (SaleInvoice $record) => $record->status === 'draft'),

            Actions\Action::make('post')
                ->label('Contabilizar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (SaleInvoice $record) => $record->status === 'draft'
                    && auth()->user()?->can('sales.post'))
                ->requiresConfirmation()
                ->modalHeading('Contabilizar factura de venta')
                ->modalDescription(fn (SaleInvoice $record) => sprintf(
                    'Vas a contabilizar la factura %s por $%s. Se generará el asiento contable, salida de inventario y se reservará el consecutivo DIAN si la sede tiene resolución asignada. No podrás editarla después.',
                    $record->fullNumber(),
                    number_format((float) $record->total, 2),
                ))
                ->modalSubmitActionLabel('Contabilizar')
                ->action(function (SaleInvoice $record) {
                    try {
                        $invoice = app(SaleInvoiceEngine::class)->post($record);
                        Notification::make()
                            ->success()
                            ->title('Factura contabilizada')
                            ->body("Asiento {$invoice->journalEntry?->fullNumber()} creado. Número final: {$invoice->fullNumber()}.")
                            ->send();
                        $this->refreshFormData(['status', 'payment_status', 'journal_entry_id', 'prefix', 'number']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Error al contabilizar')
                            ->body($e->getMessage())
                            ->persistent()
                            ->send();
                    }
                }),

            Actions\Action::make('sendDian')
                ->label(fn (SaleInvoice $record) => $record->dian_status === SaleInvoice::DIAN_REJECTED ? 'Reenviar a DIAN' : 'Enviar a DIAN')
                ->icon('heroicon-o-paper-airplane')
                ->color(fn (SaleInvoice $record) => $record->dian_status === SaleInvoice::DIAN_REJECTED ? 'warning' : 'primary')
                ->visible(fn (SaleInvoice $record) => $record->canResendToDian()
                    && auth()->user()?->can('sales.send_dian'))
                ->requiresConfirmation()
                ->modalHeading('Enviar factura electrónica a DIAN')
                ->modalDescription(fn (SaleInvoice $record) => sprintf(
                    'Se enviará la factura %s al servicio apidian.emprenddi.com. Si DIAN la acepta, recibiremos el CUFE y el QR oficial. %s',
                    $record->fullNumber(),
                    $record->dian_status === SaleInvoice::DIAN_REJECTED
                        ? 'Esta factura fue rechazada antes — corrige los datos del cliente/empresa antes de reintentar.'
                        : '',
                ))
                ->modalSubmitActionLabel('Enviar')
                ->action(function (SaleInvoice $record) {
                    try {
                        $result = app(DianInvoiceSender::class)->send($record);
                        if ($result['ok']) {
                            Notification::make()
                                ->success()
                                ->title('Factura aceptada por DIAN')
                                ->body('CUFE: '.substr((string) $result['cufe'], 0, 32).'…')
                                ->send();
                        } else {
                            Notification::make()
                                ->danger()
                                ->title('DIAN rechazó la factura')
                                ->body($result['message'])
                                ->persistent()
                                ->send();
                        }
                        $this->refreshFormData(['dian_status', 'dian_status_code', 'cufe', 'qr_url', 'dian_error_message', 'dian_sent_at']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Error al enviar a DIAN')
                            ->body($e->getMessage())
                            ->persistent()
                            ->send();
                    }
                }),

            Actions\Action::make('createCreditNote')
                ->label('Crear Nota Crédito')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (SaleInvoice $r) => $r->isPosted()
                    && auth()->user()?->can('credit_debit_notes.create'))
                ->action(fn (SaleInvoice $r) => $this->createNoteFromInvoice($r, 'credit')),

            Actions\Action::make('createDebitNote')
                ->label('Crear Nota Débito')
                ->icon('heroicon-o-arrow-uturn-right')
                ->color('info')
                ->visible(fn (SaleInvoice $r) => $r->isPosted()
                    && auth()->user()?->can('credit_debit_notes.create'))
                ->action(fn (SaleInvoice $r) => $this->createNoteFromInvoice($r, 'debit')),
        ];
    }

    /**
     * Crea una NC/ND draft con datos copiados de la factura — el usuario
     * después ajusta cantidades/líneas si la nota es parcial, define motivo,
     * y postea por el flujo normal.
     */
    protected function createNoteFromInvoice(SaleInvoice $invoice, string $type): void
    {
        $invoice->loadMissing('lines');

        $company = Company::find($invoice->company_id);
        $prefix = $type === 'credit' ? 'NC' : 'ND';
        $defaultReason = $type === 'credit' ? 1 : 1;

        $note = CreditDebitNote::create([
            'company_id' => $invoice->company_id,
            'location_id' => $invoice->location_id,
            'third_party_id' => $invoice->third_party_id,
            'sale_invoice_id' => $invoice->id,
            'type' => $type,
            'prefix' => $prefix,
            'number' => app(CreditDebitNoteNumberer::class)->next($company, $type, $prefix),
            'date' => now()->toDateString(),
            'reason_code' => $defaultReason,
            'currency' => $invoice->currency,
            'exchange_rate' => $invoice->exchange_rate,
            'subtotal' => $invoice->subtotal,
            'discount_total' => $invoice->discount_total,
            'tax_total' => $invoice->tax_total,
            'total' => $invoice->total,
            'status' => 'draft',
            'created_by_user_id' => auth()->id(),
            'description' => sprintf(
                '%s sobre factura %s',
                $type === 'credit' ? 'Nota Crédito' : 'Nota Débito',
                $invoice->fullNumber(),
            ),
        ]);

        // Copiar líneas de la factura
        $lineNum = 1;
        foreach ($invoice->lines as $line) {
            $note->lines()->create([
                'line_number' => $lineNum++,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'cost_at_return' => $line->cost_at_sale ?? 0,
                'discount_percentage' => $line->discount_percentage,
                'discount_amount' => $line->discount_amount,
                'tax_id' => $line->tax_id,
                'tax_rate' => $line->tax_rate,
                'tax_amount' => $line->tax_amount,
                'subtotal' => $line->subtotal,
                'total' => $line->total,
            ]);
        }

        Notification::make()
            ->title(($type === 'credit' ? 'Nota Crédito' : 'Nota Débito').' creada — '.$note->fullNumber())
            ->body('Ajusta cantidades y motivo, luego contabiliza.')
            ->success()
            ->send();

        $this->redirect(CreditDebitNoteResource::getUrl('edit', ['record' => $note]));
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('full_number')
                        ->label('Número')
                        ->state(fn (SaleInvoice $r) => $r->fullNumber())
                        ->fontFamily('mono')->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('due_date')->label('Vence')->date('Y-m-d')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $s) => SaleInvoice::STATUSES[$s] ?? $s)
                        ->badge()
                        ->color(fn (string $s) => match ($s) {
                            'posted' => 'success', 'cancelled' => 'danger', default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('customer.name')->label('Cliente')->columnSpan(2),
                    Infolists\Components\TextEntry::make('location.name')->label('Sede'),
                    Infolists\Components\TextEntry::make('seller.name')->label('Vendedor')->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Líneas')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('lines')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('product.code')
                                ->label('SKU')->fontFamily('mono')
                                ->placeholder('—')->columnSpan(2),
                            Infolists\Components\TextEntry::make('description')->label('Descripción')->columnSpan(4),
                            Infolists\Components\TextEntry::make('quantity')->label('Cant.')->numeric(decimalPlaces: 2)->columnSpan(1),
                            Infolists\Components\TextEntry::make('unit_price')->label('Precio unit.')->money('COP')->columnSpan(2),
                            Infolists\Components\TextEntry::make('tax.code')->label('Imp.')->placeholder('—')->columnSpan(1),
                            Infolists\Components\TextEntry::make('total')->label('Total')->money('COP')->weight('semibold')->columnSpan(2),
                        ])
                        ->columns(12),
                ]),

            Infolists\Components\Section::make('Retenciones')
                ->visible(fn (SaleInvoice $r) => (float) $r->retention_total > 0)
                ->schema([
                    Infolists\Components\RepeatableEntry::make('retentions')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('tax_code')->label('Código')->fontFamily('mono')->columnSpan(2),
                            Infolists\Components\TextEntry::make('tax_name')->label('Concepto')->columnSpan(5),
                            Infolists\Components\TextEntry::make('base_amount')->label('Base')->money('COP')->columnSpan(2),
                            Infolists\Components\TextEntry::make('rate')->label('%')->formatStateUsing(fn ($s) => number_format((float) $s, 4).' %')->columnSpan(1),
                            Infolists\Components\TextEntry::make('amount')->label('Retenido')->money('COP')->weight('semibold')->columnSpan(2),
                        ])
                        ->columns(12),
                ]),

            Infolists\Components\Section::make('Totales')
                ->columns(6)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('COP'),
                    Infolists\Components\TextEntry::make('discount_total')->label('Descuento')->money('COP'),
                    Infolists\Components\TextEntry::make('tax_total')->label('IVA')->money('COP'),
                    Infolists\Components\TextEntry::make('total')->label('Total')->money('COP'),
                    Infolists\Components\TextEntry::make('retention_total')->label('Retenciones')->money('COP'),
                    Infolists\Components\TextEntry::make('net_payable')->label('NETO A PAGAR')->money('COP')->weight('bold'),
                ]),

            Infolists\Components\Section::make('Pagado / Saldo')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('paid_amount')->label('Pagado')->money('COP')->weight('semibold'),
                    Infolists\Components\TextEntry::make('balance')
                        ->label('Saldo pendiente')
                        ->state(fn (SaleInvoice $r) => $r->balance)
                        ->money('COP')
                        ->weight('semibold'),
                    Infolists\Components\TextEntry::make('payment_status')
                        ->label('Estado pago')
                        ->formatStateUsing(fn (string $s) => SaleInvoice::PAYMENT_STATUSES[$s] ?? $s)
                        ->badge()
                        ->color(fn (string $s) => match ($s) {
                            'pendiente' => 'warning', 'parcial' => 'info', 'pagado' => 'success',
                            'vencido' => 'danger', 'cancelada' => 'gray', default => 'gray',
                        }),
                ]),

            Infolists\Components\Section::make('Asiento contable')
                ->visible(fn (SaleInvoice $r) => $r->journal_entry_id !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('journalEntry.full_number')
                        ->label('Asiento')
                        ->state(fn (SaleInvoice $r) => $r->journalEntry?->fullNumber())
                        ->url(fn (SaleInvoice $r) => $r->journalEntry
                            ? route('filament.app.resources.journal-entries.view', $r->journalEntry)
                            : null),
                ]),

            Infolists\Components\Section::make('Facturación electrónica DIAN')
                ->visible(fn (SaleInvoice $r) => $r->dian_status !== null)
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('dian_status')
                        ->label('Estado')
                        ->formatStateUsing(fn (?string $s) => $s ? (SaleInvoice::DIAN_STATUSES[$s] ?? $s) : '—')
                        ->badge()
                        ->color(fn (?string $s) => match ($s) {
                            'accepted' => 'success',
                            'sent' => 'info',
                            'pending' => 'gray',
                            'rejected' => 'danger',
                            default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('dian_status_code')->label('Código DIAN')->placeholder('—'),
                    Infolists\Components\TextEntry::make('dian_sent_at')->label('Último envío')->dateTime('Y-m-d H:i:s')->placeholder('—'),
                    Infolists\Components\TextEntry::make('cufe')
                        ->label('CUFE')
                        ->columnSpan(3)
                        ->fontFamily('mono')
                        ->placeholder('—')
                        ->copyable()
                        ->copyMessage('CUFE copiado'),
                    Infolists\Components\TextEntry::make('qr_url')
                        ->label('QR DIAN')
                        ->columnSpan(3)
                        ->placeholder('—')
                        ->url(fn (SaleInvoice $r) => $r->qr_url, true)
                        ->openUrlInNewTab(),
                    Infolists\Components\TextEntry::make('dian_error_message')
                        ->label('Mensaje de error')
                        ->columnSpan(3)
                        ->visible(fn (SaleInvoice $r) => ! empty($r->dian_error_message))
                        ->color('danger'),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (SaleInvoice $r) => $r->notes !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label(''),
                ]),
        ]);
    }
}
