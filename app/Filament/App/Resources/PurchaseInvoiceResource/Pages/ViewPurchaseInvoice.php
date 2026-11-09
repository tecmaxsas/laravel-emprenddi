<?php

namespace App\Filament\App\Resources\PurchaseInvoiceResource\Pages;

use App\Filament\App\Resources\PurchaseInvoiceResource;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\PurchaseInvoiceEngine;
use App\Support\CashSessionGate;
use App\Support\LabelsSettings;
use App\Support\PaymentAccountResolver;
use App\Support\PaymentMethodOptions;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseInvoice extends ViewRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    public function getTitle(): string
    {
        return 'Factura '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->adjuntarSoporteAction(),

            Actions\EditAction::make()
                ->visible(fn (PurchaseInvoice $record) => $record->status === 'draft'),

            Actions\Action::make('post')
                ->label('Contabilizar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (PurchaseInvoice $record) => $record->status === 'draft'
                    && auth()->user()?->can('purchases.post'))
                ->requiresConfirmation()
                ->modalHeading('Contabilizar factura de compra')
                ->modalDescription(fn (PurchaseInvoice $record) => sprintf(
                    'Vas a contabilizar la factura %s por $%s. Se generará el asiento contable y los movimientos de inventario. No podrás editarla después.',
                    $record->fullNumber(),
                    number_format((float) $record->total, 2),
                ))
                ->modalSubmitActionLabel('Contabilizar')
                // EL PAGO, AQUI MISMO.
                //
                // Una compra que se paga de contado obligaba a tres pasos:
                // contabilizar, abrir la pestaña de pagos y registrar. Y el
                // usuario ya esta en el momento en que sabe si la pago o no:
                // acaba de recibir la mercancia con la factura en la mano.
                //
                // Va como interruptor apagado, no como campos siempre
                // visibles: la compra a credito es igual de corriente, y a
                // quien no paga hoy no se le pone un formulario de pago
                // delante.
                ->fillForm(fn (PurchaseInvoice $record) => [
                    'pagar_ahora' => false,
                    'amount' => (float) ($record->net_payable ?: $record->total),
                    'date' => now()->toDateString(),
                    'payment_method' => 'cash',
                ])
                ->form([
                    Forms\Components\Toggle::make('pagar_ahora')
                        ->label('Registrar el pago ahora')
                        // Un pago a proveedor exige turno de caja abierto: esa
                        // plata sale del cajon y tiene que quedar en el arqueo
                        // de alguien. Se dice AQUI y se bloquea el interruptor,
                        // en vez de dejar que el usuario lo encienda, llene el
                        // formulario y se entere despues de contabilizar —con
                        // la factura ya posteada y el pago no—.
                        ->disabled(fn () => CashSessionGate::currentOpenSession() === null)
                        ->helperText(fn () => CashSessionGate::currentOpenSession() === null
                            ? 'Para registrar el pago hay que tener una caja abierta: ese dinero sale del cajón '
                                .'y tiene que quedar en el arqueo del turno.'
                            : 'Si ya le pagaste al proveedor. Si es a crédito, déjalo apagado.')
                        ->live(),

                    Forms\Components\TextInput::make('amount')
                        ->label('Valor pagado')
                        ->numeric()
                        ->prefix('$')
                        ->required(fn (Forms\Get $get) => (bool) $get('pagar_ahora'))
                        ->visible(fn (Forms\Get $get) => (bool) $get('pagar_ahora'))
                        // Viene con el neto a pagar: el abono parcial existe,
                        // pero lo corriente es pagar la factura completa y
                        // volver a teclear la cifra es donde se escribe mal.
                        ->helperText('Puedes dejarlo en el total o registrar un abono parcial.'),

                    Forms\Components\DatePicker::make('date')
                        ->label('Fecha del pago')
                        ->required(fn (Forms\Get $get) => (bool) $get('pagar_ahora'))
                        ->visible(fn (Forms\Get $get) => (bool) $get('pagar_ahora')),

                    Forms\Components\Select::make('payment_method')
                        ->label('Forma de pago')
                        // Las de la empresa, no las de fabrica: si se paga por
                        // Nequi y solo aparece «Otro», el arqueo deja de
                        // cuadrar contra el extracto.
                        ->options(fn () => PaymentMethodOptions::para())
                        ->native(false)
                        ->required(fn (Forms\Get $get) => (bool) $get('pagar_ahora'))
                        ->visible(fn (Forms\Get $get) => (bool) $get('pagar_ahora')),

                    Forms\Components\TextInput::make('reference')
                        ->label('Referencia')
                        ->helperText('Número de consignación, cheque, etc.')
                        ->visible(fn (Forms\Get $get) => (bool) $get('pagar_ahora')),
                ])
                ->action(function (PurchaseInvoice $record, array $data) {
                    try {
                        $invoice = app(PurchaseInvoiceEngine::class)->post($record);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Error al contabilizar')
                            ->body($e->getMessage())
                            ->persistent()
                            ->send();

                        return;
                    }

                    if (! ($data['pagar_ahora'] ?? false)) {
                        Notification::make()
                            ->success()
                            ->title('Factura contabilizada')
                            ->body("Asiento {$invoice->journalEntry?->fullNumber()} creado.")
                            ->send();

                        $this->refreshFormData(['status', 'payment_status', 'journal_entry_id']);

                        return;
                    }

                    try {
                        app(PurchaseInvoiceEngine::class)->addPayment($invoice->fresh(), [
                            'amount' => round((float) $data['amount'], 2),
                            'date' => $data['date'],
                            'payment_method' => $data['payment_method'],
                            'reference' => $data['reference'] ?? null,
                            'account_id' => PaymentAccountResolver::forMethod($data['payment_method']),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Factura contabilizada y pagada')
                            ->body("Asiento {$invoice->journalEntry?->fullNumber()} creado.")
                            ->send();
                    } catch (\Throwable $e) {
                        // La factura SI quedo contabilizada: solo fallo el
                        // pago. Se dice, porque si no el usuario vuelve a
                        // intentar todo y termina con la compra duplicada.
                        Notification::make()
                            ->warning()
                            ->title('Se contabilizó, pero el pago no se registró')
                            ->body($e->getMessage().' Regístralo desde la pestaña de pagos.')
                            ->persistent()
                            ->send();
                    }

                    $this->refreshFormData(['status', 'payment_status', 'journal_entry_id', 'paid_amount']);
                }),

            Actions\Action::make('printLabels')
                ->label('Imprimir etiquetas')
                ->icon('heroicon-o-qr-code')
                ->color('info')
                ->visible(fn (PurchaseInvoice $record) => LabelsSettings::enabled()
                    && $record->status === 'posted')
                ->modalHeading('Imprimir etiquetas de los productos comprados')
                ->modalDescription('Se generará una etiqueta por cada unidad recibida (usando la cantidad de cada línea). Puedes ajustar el multiplicador si necesitas más o menos.')
                ->form([
                    Forms\Components\TextInput::make('multiplier')
                        ->label('Multiplicador de cantidad')
                        ->numeric()->minValue(1)->maxValue(100)->default(1)
                        ->helperText('1 = una etiqueta por unidad recibida. 2 = doble. Etc.')
                        ->required(),
                ])
                ->action(function (PurchaseInvoice $record, array $data, $livewire) {
                    $mult = max(1, (int) ($data['multiplier'] ?? 1));
                    $spec = $record->lines()
                        ->whereNotNull('product_id')
                        ->get(['product_id', 'quantity'])
                        ->map(fn ($l) => $l->product_id.':'.max(1, (int) round((float) $l->quantity) * $mult))
                        ->implode(',');
                    if (! $spec) {
                        Notification::make()
                            ->title('Sin productos con SKU en esta factura')->warning()->send();

                        return;
                    }
                    $url = route('labels.print', ['products' => $spec]);
                    $livewire->js('window.open('.json_encode($url).", '_blank')");
                }),

            Actions\Action::make('cancel')
                ->label('Anular factura')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (PurchaseInvoice $record) => $record->status === 'posted'
                    && auth()->user()?->can('purchases.post'))
                ->requiresConfirmation()
                ->modalHeading('Anular factura de compra')
                ->modalDescription(fn (PurchaseInvoice $record) => sprintf(
                    'Vas a anular la factura %s. Se devuelve el inventario al proveedor y se crea un asiento de reversa que neutraliza el efecto contable. No se puede anular si tiene pagos registrados.',
                    $record->fullNumber(),
                ))
                ->modalSubmitActionLabel('Anular')
                ->action(function (PurchaseInvoice $record) {
                    try {
                        app(PurchaseInvoiceEngine::class)->cancel($record);
                        Notification::make()
                            ->success()
                            ->title('Factura anulada')
                            ->body('Se reversaron inventario y asiento contable.')
                            ->send();
                        $this->refreshFormData(['status', 'payment_status']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('No se pudo anular')
                            ->body($e->getMessage())
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }

    /**
     * Adjuntar la foto o el PDF del proveedor, contabilizada o no.
     *
     * Tiene accion propia en vez de ser un campo mas del formulario porque el
     * formulario se cierra al contabilizar —y con razon: ahi ya no se tocan
     * cantidades ni impuestos—. Pero el soporte NO es dato contable: es la
     * prueba de lo que se digito.
     *
     * Y el caso corriente es justamente ese: la factura se registra hoy
     * porque hay que pagarla, y el papel llega con el pedido tres dias
     * despues. Si solo se pudiera adjuntar en borrador, el caso corriente
     * quedaria sin soporte para siempre.
     */
    protected function adjuntarSoporteAction(): Actions\Action
    {
        return Actions\Action::make('adjuntarSoporte')
            ->label(fn (PurchaseInvoice $record) => empty($record->attachment_paths)
                ? 'Adjuntar soporte'
                : 'Soportes ('.count($record->attachment_paths).')')
            ->icon('heroicon-o-paper-clip')
            ->color('gray')
            ->modalHeading('Soporte de la factura del proveedor')
            ->modalDescription('La foto o el PDF que mandó el proveedor. Varias hojas, varios archivos.')
            ->modalSubmitActionLabel('Guardar')
            ->fillForm(fn (PurchaseInvoice $record) => [
                'attachment_paths' => $record->attachment_paths ?? [],
            ])
            ->form([
                Forms\Components\FileUpload::make('attachment_paths')
                    ->label('')
                    ->multiple()
                    ->reorderable()
                    ->openable()
                    ->downloadable()
                    ->acceptedFileTypes(['image/*', 'application/pdf'])
                    ->disk('public')
                    ->directory('compras/soportes/'.now()->format('Y/m'))
                    ->maxSize(10240)
                    ->panelLayout('grid')
                    ->helperText('Hasta 10 MB por archivo.')
                    ->columnSpanFull(),
            ])
            ->action(function (PurchaseInvoice $record, array $data) {
                // forceFill: `attachment_paths` es lo unico que se toca, y asi
                // no pasa por la validacion que cierra la factura al
                // contabilizarla —que existe para las cifras, no para esto—.
                $record->forceFill([
                    'attachment_paths' => $data['attachment_paths'] ?? [],
                ])->save();

                Notification::make()
                    ->success()
                    ->title('Soporte guardado')
                    ->send();
            });
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('full_number')
                        ->label('Número')
                        ->state(fn (PurchaseInvoice $r) => $r->fullNumber())
                        ->fontFamily('mono')->weight('bold'),
                    Infolists\Components\TextEntry::make('date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('due_date')->label('Vence')->date('Y-m-d')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')
                        ->formatStateUsing(fn (string $state) => PurchaseInvoice::STATUSES[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'posted' => 'success', 'cancelled' => 'danger', default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('supplier.name')->label('Proveedor')->columnSpan(2),
                    Infolists\Components\TextEntry::make('location.name')->label('Sede'),
                    Infolists\Components\TextEntry::make('supplier_invoice_number')->label('N° proveedor')->placeholder('—'),
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
                            Infolists\Components\TextEntry::make('unit_cost')->label('Costo unit.')->money('COP')->columnSpan(2),
                            Infolists\Components\TextEntry::make('tax.code')->label('Imp.')->placeholder('—')->columnSpan(1),
                            Infolists\Components\TextEntry::make('total')->label('Total')->money('COP')->weight('semibold')->columnSpan(2),
                        ])
                        ->columns(12),
                ]),

            Infolists\Components\Section::make('Totales')
                ->columns(5)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('COP'),
                    Infolists\Components\TextEntry::make('discount_total')->label('Descuento')->money('COP'),
                    Infolists\Components\TextEntry::make('tax_total')->label('IVA')->money('COP'),
                    Infolists\Components\TextEntry::make('total')->label('TOTAL')->money('COP')->weight('bold'),
                    Infolists\Components\TextEntry::make('payment_status')
                        ->label('Estado pago')
                        ->formatStateUsing(fn (string $state) => PurchaseInvoice::PAYMENT_STATUSES[$state] ?? $state)
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'pendiente' => 'warning', 'parcial' => 'info', 'pagado' => 'success',
                            'vencido' => 'danger', 'cancelada' => 'gray', default => 'gray',
                        }),
                ]),

            Infolists\Components\Section::make('Pagado / Saldo')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('paid_amount')->label('Pagado')->money('COP')->weight('semibold'),
                    Infolists\Components\TextEntry::make('balance')
                        ->label('Saldo pendiente')
                        ->state(fn (PurchaseInvoice $r) => $r->balance)
                        ->money('COP')
                        ->weight('semibold'),
                ]),

            Infolists\Components\Section::make('Asiento contable')
                ->visible(fn (PurchaseInvoice $r) => $r->journal_entry_id !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('journalEntry.full_number')
                        ->label('Asiento')
                        ->state(fn (PurchaseInvoice $r) => $r->journalEntry?->fullNumber())
                        ->url(fn (PurchaseInvoice $r) => $r->journalEntry
                            ? route('filament.app.resources.journal-entries.view', $r->journalEntry)
                            : null),
                ]),

            Infolists\Components\Section::make('Soporte del proveedor')
                ->visible(fn (PurchaseInvoice $record) => ! empty($record->attachment_paths))
                ->schema([
                    Infolists\Components\ImageEntry::make('attachment_paths')
                        ->label('')
                        ->disk('public')
                        ->height(140)
                        ->stacked()
                        ->limit(8)
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Notas')
                ->visible(fn (PurchaseInvoice $r) => $r->notes !== null)
                ->schema([
                    Infolists\Components\TextEntry::make('notes')->label(''),
                ]),
        ]);
    }
}
