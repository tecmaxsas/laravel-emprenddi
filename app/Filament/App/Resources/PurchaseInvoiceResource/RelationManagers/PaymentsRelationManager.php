<?php

namespace App\Filament\App\Resources\PurchaseInvoiceResource\RelationManagers;

use App\Models\Account;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Services\Purchases\PurchaseInvoiceEngine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Pagos';

    protected static ?string $modelLabel = 'Pago';

    protected static ?string $pluralModelLabel = 'Pagos';

    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
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
                ->options(Payment::PAYMENT_METHODS)
                ->default('cash')
                ->required()
                ->native(false),

            Forms\Components\Select::make('account_id')
                ->label('Cuenta de caja/banco')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => Account::query()
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
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d'),
                Tables\Columns\TextColumn::make('amount')->label('Monto')->money('COP')->weight('semibold')->alignEnd(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Método')
                    ->formatStateUsing(fn (string $s) => Payment::PAYMENT_METHODS[$s] ?? $s)
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
                    ->label('Registrar pago')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn () => $this->getOwnerRecord()->isPosted()
                        && ! $this->getOwnerRecord()->isFullyPaid()
                        && auth()->user()?->can('purchases.pay'))
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
            ]);
    }
}
