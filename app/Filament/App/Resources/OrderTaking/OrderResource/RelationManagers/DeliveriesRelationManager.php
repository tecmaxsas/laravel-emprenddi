<?php

namespace App\Filament\App\Resources\OrderTaking\OrderResource\RelationManagers;

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

                Tables\Columns\TextColumn::make('deliveredBy.name')
                    ->label('Despachó')->placeholder('—')->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('registerPayment')
                    ->label('Registrar abono')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn () => $this->pedido()->status !== Order::STATUS_CANCELLED
                        && (float) $this->pedido()->balance > 0)
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

        // Se propone lo que falta de ESTE despacho, sin pasarse del saldo del
        // pedido. El tope duro sigue siendo el saldo, porque es normal que un
        // solo pago cubra mas de una entrega.
        $sugerido = min($pendienteDespacho ?: $saldoPedido, $saldoPedido);

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
                ->minValue(0.01)->maxValue($saldoPedido)
                ->default($sugerido)
                ->helperText('El tope es el saldo del pedido, no el de este despacho: un mismo pago puede cubrir varias entregas.'),

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
