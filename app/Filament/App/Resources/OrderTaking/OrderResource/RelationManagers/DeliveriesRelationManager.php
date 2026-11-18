<?php

namespace App\Filament\App\Resources\OrderTaking\OrderResource\RelationManagers;

use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\Account;
use App\Models\OrderTaking\Delivery;
use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\Payment;
use App\Services\OrderTaking\OrderEngine;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Despachos del pedido, con sus abonos colgando de cada uno.
 *
 * Cada despacho se factura aparte desde aqui, por lo que salio en el. Los
 * abonos del despacho pasan a su factura; despues de facturar, los pagos se
 * registran en la factura.
 *
 * El abono se registra desde aqui y no desde la cabecera del pedido: primero
 * se despacha, y el pago queda atado a la entrega que lo origina. Asi se puede
 * responder "que me pagaron de la remision 045" sin adivinar.
 */
class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Despachos y abonos';

    protected static ?string $modelLabel = 'Despacho';

    protected static ?string $pluralModelLabel = 'Despachos';

    public function form(Form $form): Form
    {
        // Los despachos se crean desde la accion "Registrar despacho" de la
        // cabecera, que valida cantidades pendientes linea por linea.
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('delivery_number')
            ->defaultSort('delivery_date', 'desc')
            ->emptyStateHeading('Todavía no hay despachos')
            ->emptyStateDescription('Registra un despacho desde el botón "Registrar despacho" para poder abonar contra él.')
            ->columns([
                Tables\Columns\TextColumn::make('delivery_number')
                    ->label('Remisión')
                    ->state(fn (Delivery $record) => $record->label())
                    ->fontFamily('mono')
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('delivery_date')
                    ->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('unidades')
                    ->label('Unidades')
                    ->state(fn (Delivery $record) => (float) $record->items->sum('quantity_delivered'))
                    ->numeric(decimalPlaces: 2)
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('valor')
                    ->label('Valor despachado')
                    ->state(fn (Delivery $record) => $record->value())
                    ->money('COP')->alignEnd()->weight('semibold'),

                Tables\Columns\TextColumn::make('abonado')
                    ->label('Abonado')
                    ->state(fn (Delivery $record) => $record->paidAmount())
                    ->money('COP')->alignEnd()->color('success'),

                Tables\Columns\TextColumn::make('pendiente')
                    ->label('Pendiente')
                    ->state(fn (Delivery $record) => max(0, round($record->value() - $record->paidAmount(), 2)))
                    ->money('COP')->alignEnd()
                    ->color(fn (Delivery $record) => $record->paidAmount() + 0.01 >= $record->value() ? 'success' : 'warning')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('saleInvoice.number')
                    ->label('Factura')
                    ->state(fn (Delivery $record) => $record->saleInvoice?->fullNumber())
                    ->fontFamily('mono')->badge()->color('success')->placeholder('Sin facturar')
                    ->url(fn (Delivery $record) => $record->isInvoiced()
                        ? SaleInvoiceResource::getUrl('view', ['record' => $record->sale_invoice_id])
                        : null),

                Tables\Columns\TextColumn::make('deliveredBy.name')
                    ->label('Despachó')->placeholder('—')->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('registerPayment')
                    ->label('Registrar abono')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    // Ya facturado, el abono se registra en la factura: aqui no
                    // llegaria a ella porque los abonos se copian al facturar.
                    ->visible(fn (Delivery $record) => $this->pedido()->status !== Order::STATUS_CANCELLED
                        && ! $this->pedido()->isInvoiced()
                        && ! $record->isInvoiced()
                        && (float) $this->pedido()->balance > 0
                        && $record->paidAmount() + 0.01 < $record->value())
                    ->form(fn (Delivery $record) => $this->paymentForm($record))
                    ->action(function (Delivery $record, array $data) {
                        try {
                            app(OrderEngine::class)->registerPayment($record, $data);
                            Notification::make()->success()->title('Abono registrado')->send();
                            $this->dispatch('refreshOrder');
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('No se pudo registrar el abono')
                                ->body($e->getMessage())->persistent()->send();
                        }
                    }),

                Tables\Actions\Action::make('invoice')
                    ->label('Facturar')
                    ->icon('heroicon-o-document-currency-dollar')
                    ->color('primary')
                    ->visible(fn (Delivery $record) => ! $record->isInvoiced()
                        && ! $this->pedido()->isInvoiced()
                        && $this->pedido()->status !== Order::STATUS_CANCELLED)
                    ->modalHeading(fn (Delivery $record) => 'Facturar '.$record->label())
                    ->modalDescription(fn (Delivery $record) => 'Se emite la factura por lo que salió en este despacho ($'
                        .number_format($record->value(), 0, ',', '.').'), se descarga el inventario y se contabiliza la venta. '
                        .'Los abonos de este despacho pasan como pagos de la factura.')
                    ->modalSubmitActionLabel('Emitir factura')
                    ->form([
                        Forms\Components\Select::make('invoice_kind')
                            ->label('Tipo de factura')
                            ->native(false)
                            ->required()
                            ->default('pos')
                            ->options([
                                'pos' => 'POS (no electrónica)',
                                'electronic' => 'Electrónica DIAN',
                            ])
                            ->helperText('La numeración sale de la resolución configurada en la sede del pedido.'),

                        Forms\Components\Toggle::make('allow_negative_stock')
                            ->label('Facturar aunque no haya saldo en bodega')
                            ->helperText('La factura descarga inventario. Actívalo solo si este negocio no lleva existencias; si las lleva, un saldo en cero suele significar que falta registrar la compra.'),
                    ])
                    ->action(function (Delivery $record, array $data) {
                        try {
                            $invoice = app(OrderEngine::class)->invoiceDelivery(
                                $record,
                                $data['invoice_kind'] ?? 'pos',
                                (bool) ($data['allow_negative_stock'] ?? false),
                            );

                            Notification::make()
                                ->success()
                                ->title('Factura '.$invoice->fullNumber().' emitida')
                                ->body($record->label().' queda enlazado a la factura.')
                                ->send();

                            $this->redirect(SaleInvoiceResource::getUrl('view', ['record' => $invoice]));
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('No se pudo facturar')
                                ->body($e->getMessage())->persistent()->send();
                        }
                    }),
            ])
            ->headerActions([])
            ->paginated(false);
    }

    protected function pedido(): Order
    {
        return $this->getOwnerRecord();
    }

    protected function paymentForm(Delivery $delivery): array
    {
        $order = $this->pedido();
        $saldoPedido = (float) $order->balance;
        $pendienteDespacho = max(0, round($delivery->value() - $delivery->paidAmount(), 2));

        // Tope: lo que falta de ESTE despacho, sin pasarse del saldo del
        // pedido. Cada despacho se factura aparte y sus abonos pasan a esa
        // factura, asi que no puede recibir mas de lo que vale.
        $tope = min($pendienteDespacho, $saldoPedido);
        $sugerido = $tope;

        return [
            Forms\Components\Placeholder::make('resumen')
                ->hiddenLabel()
                ->content(new HtmlString(sprintf(
                    '<div style="font-size:12.5px; line-height:1.7;">'
                    .'<strong>%s</strong><br>'
                    .'Valor despachado: <strong>$%s</strong> · Ya abonado: <strong>$%s</strong><br>'
                    .'Pendiente de este despacho: <strong style="color:#b45309;">$%s</strong><br>'
                    .'Saldo del pedido: <strong>$%s</strong>'
                    .'</div>',
                    e($delivery->label()),
                    number_format($delivery->value(), 2),
                    number_format($delivery->paidAmount(), 2),
                    number_format($pendienteDespacho, 2),
                    number_format($saldoPedido, 2),
                ))),

            Forms\Components\TextInput::make('amount')
                ->label('Monto')->numeric()->required()->prefix('$')
                ->minValue(0.01)->maxValue($tope)
                ->default($sugerido)
                ->helperText('El tope es lo pendiente de este despacho. Si un pago cubre varias entregas, registra un abono en cada una.'),

            Forms\Components\Select::make('payment_method')
                ->label('Método')->native(false)->required()
                ->options(Payment::METHODS)->default('cash'),

            Forms\Components\Select::make('account_id')
                ->label('Cuenta contable (opcional)')->native(false)->searchable()
                // Sin contabilidad el campo no le dice nada al usuario.
                ->visible(fn () => ModuleGate::active(ModuleGate::ACCOUNTING))
                ->options(fn () => Account::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->where('accepts_movements', true)
                    ->where('code', 'like', '11%')
                    ->orderBy('code')
                    ->get()
                    ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                    ->all()),

            Forms\Components\DatePicker::make('payment_date')
                ->label('Fecha')->native(false)->default(now())->required(),

            Forms\Components\TextInput::make('reference')->label('Referencia'),
            Forms\Components\Textarea::make('notes')->label('Notas')->rows(2),
        ];
    }
}
