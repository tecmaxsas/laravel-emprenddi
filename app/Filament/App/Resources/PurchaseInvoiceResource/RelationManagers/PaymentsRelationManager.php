<?php

namespace App\Filament\App\Resources\PurchaseInvoiceResource\RelationManagers;

use App\Models\Account;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Purchases\PurchaseInvoiceEngine;
use App\Services\Sales\PaymentDeleter;
use App\Support\CashSessionGate;
use App\Support\PaymentMethodOptions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Pagos';

    protected static ?string $modelLabel = 'Pago';

    protected static ?string $pluralModelLabel = 'Pagos';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->isPosted();
    }

    public function form(Form $form): Form
    {
        // El form de detalle (cuando se ve un pago individual) usa el mismo schema
        return $form->columns(2)->schema($this->paymentFormSchema());
    }

    /**
     * Schema del form de pago — usado tanto en el form de detalle del pago
     * como en el modal del action 'Registrar pago'.
     */
    protected function paymentFormSchema(): array
    {
        return [
            Forms\Components\DatePicker::make('date')
                ->label('Fecha del pago')
                ->required()
                ->default(now()),

            Forms\Components\TextInput::make('amount')
                ->label('Monto')
                ->required()
                ->numeric()
                ->minValue(0.01)
                ->prefix('$')
                ->default(fn () => $this->getOwnerRecord()->balance)
                ->helperText(fn () => 'Saldo pendiente: $'.number_format($this->getOwnerRecord()->balance, 2)),

            Forms\Components\Select::make('payment_method')
                ->label('Método de pago')
                ->options(fn () => PaymentMethod::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->where('active', true)
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->pluck('name', 'code')
                    ->all() ?: Payment::PAYMENT_METHODS)
                ->default('cash')
                ->required()
                ->native(false)
                ->live()
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    $accountId = PaymentMethod::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->where('code', $state)
                        ->where('active', true)
                        ->value('account_id');
                    if ($accountId) {
                        $set('account_id', $accountId);
                    }
                }),

            Forms\Components\Select::make('account_id')
                ->label('Cuenta de caja/banco')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => Account::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->where('accepts_movements', true)
                    ->where('active', true)
                    ->where('code', 'like', '11%')
                    ->where(function ($q) use ($search) {
                        $q->where('code', 'like', "%{$search}%")
                            ->orWhere('name', 'ilike', "%{$search}%");
                    })
                    ->orderBy('code')
                    ->limit(20)
                    ->get()
                    ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                    ->all())
                ->getOptionLabelUsing(fn ($value) => Account::find($value)
                    ? Account::find($value)->code.' — '.Account::find($value)->name
                    : null)
                ->helperText('Caja general 110505, Bancos 1110, etc.'),

            Forms\Components\TextInput::make('reference')
                ->label('Referencia')
                ->maxLength(100)
                ->placeholder('N° transferencia, cheque, etc.'),

            Forms\Components\Textarea::make('description')
                ->label('Notas')
                ->rows(2)
                ->columnSpanFull(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            // `date` no lleva hora: sin desempate las filas del mismo dia salen
            // en el orden que quiera la base. Aqui no hay consecutivo, asi que
            // el `id` hace de orden de registro.
            ->defaultSort(fn (Builder $query) => $query
                ->orderByDesc('date')
                ->orderByDesc('id'))
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d'),
                Tables\Columns\TextColumn::make('amount')->label('Monto')->money('COP')->weight('semibold')->alignEnd(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Método')
                    ->formatStateUsing(fn (string $state) => PaymentMethodOptions::nombre($state))
                    ->badge(),
                Tables\Columns\TextColumn::make('account.code')->label('Cta.')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('reference')->label('Ref.')->placeholder('—'),
                Tables\Columns\TextColumn::make('journalEntry.full_number')
                    ->label('Asiento')
                    ->state(fn (Payment $p) => $p->journalEntry?->fullNumber())
                    ->placeholder('—')
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('createdBy.email')->label('Creado por')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\Action::make('addPayment')
                    ->label(fn () => CashSessionGate::hasOpenSession() ? 'Registrar pago' : 'Abre caja para pagar')
                    ->icon('heroicon-o-banknotes')
                    ->color(fn () => CashSessionGate::hasOpenSession() ? 'success' : 'gray')
                    ->visible(fn () => $this->getOwnerRecord()->isPosted()
                        && ! $this->getOwnerRecord()->isFullyPaid()
                        && auth()->user()?->can('purchases.pay'))
                    ->disabled(fn () => ! CashSessionGate::hasOpenSession())
                    ->tooltip(fn () => CashSessionGate::hasOpenSession()
                        ? null
                        : 'Necesitas abrir una caja registradora desde el POS antes de registrar el pago.')
                    ->modalHeading('Registrar pago')
                    ->modalSubmitActionLabel('Registrar')
                    ->form(fn () => $this->paymentFormSchema())
                    ->action(function (array $data) {
                        try {
                            $payment = app(PurchaseInvoiceEngine::class)->addPayment(
                                $this->getOwnerRecord(),
                                $data,
                            );
                            Notification::make()
                                ->success()
                                ->title('Pago registrado')
                                ->body("Pago de \${$payment->amount} aplicado. Asiento {$payment->journalEntry?->fullNumber()}.")
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Error al registrar pago')
                                ->body($e->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // Un cobro mal digitado —el monto equivocado, el metodo
                // equivocado, o dos veces el mismo— solo se podia deshacer
                // borrando la factura entera.
                Tables\Actions\Action::make('deletePayment')
                    ->label('Borrar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn () => auth()->user()?->can('purchases.pay'))
                    ->disabled(fn (Payment $record) => app(PaymentDeleter::class)
                        ->motivoParaNoBorrar($record) !== null)
                    // El motivo va en el tooltip: un boton gris sin explicacion
                    // deja al usuario adivinando por que no puede.
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
                    }),
            ]);
    }
}
