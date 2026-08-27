<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PaymentMethodResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\PaymentMethod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentMethodResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'payment_methods.view'; }
    protected static function managePermission(): string { return 'payment_methods.manage'; }

    protected static ?string $model = PaymentMethod::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Métodos de pago';

    protected static ?string $modelLabel = 'Método de pago';

    protected static ?string $pluralModelLabel = 'Métodos de pago';

    protected static ?string $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 60;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Código')
                        ->required()
                        ->maxLength(30)
                        ->alphaDash()
                        ->placeholder('cash, debit_card, my_voucher, ...')
                        ->helperText('Identificador estable. Cambiar el código en métodos ya usados puede romper pagos existentes.')
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule->where('company_id', auth()->user()->company_id),
                        ),

                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Efectivo, Tarjeta Visa, Bonos Sodexo...'),

                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options(PaymentMethod::TYPES)
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Orden')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->helperText('Menor = aparece primero en POS y formularios.'),
                ]),

            Forms\Components\Section::make('Cuenta contable')
                ->visible(fn () => \App\Support\ModuleGate::active(\App\Support\ModuleGate::ACCOUNTING))
                ->description('Cuenta de caja/banco donde entra el dinero al cobrar con este método. Se pre-selecciona en el comprobante de ingreso.')
                ->schema([
                    Forms\Components\Select::make('account_id')
                        ->label('Cuenta default')
                        ->relationship(
                            'account',
                            'name',
                            fn (Builder $query) => $query->where('accepts_movements', true)->orderBy('code'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Account $record) => "{$record->code} — {$record->name}")
                        ->searchable(['code', 'name'])
                        ->preload()
                        ->placeholder('— sin cuenta default —')
                        ->helperText('Caja general 110505 para efectivo. Bancos 1110xx para tarjeta/transferencia.'),
                ]),

            Forms\Components\Section::make('Comportamiento')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('requires_reference')
                        ->label('Requiere referencia')
                        ->helperText('Si está activo, el cajero deberá ingresar voucher / comprobante / N° transferencia.'),

                    Forms\Components\Toggle::make('active')
                        ->label('Activo')
                        ->default(true)
                        ->helperText('Inactivos no aparecen en POS ni en formularios de pago.'),
                ]),

            Forms\Components\Textarea::make('description')
                ->label('Descripción / notas')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable()->alignCenter(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => PaymentMethod::TYPES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'cash' => 'success',
                        'debit_card', 'credit_card' => 'info',
                        'bank_transfer', 'electronic' => 'primary',
                        'check' => 'warning',
                        'credit_note' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('account.code')
                    ->label('Cta. default')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('account.name')
                    ->label('Cuenta')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('requires_reference')
                    ->label('Ref.')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Tipo')->options(PaymentMethod::TYPES),
                Tables\Filters\TernaryFilter::make('active')->label('Activo')->default(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activar')
                        ->icon('heroicon-o-check')
                        ->action(fn ($records) => $records->each->update(['active' => true])),
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Desactivar')
                        ->icon('heroicon-o-x-mark')
                        ->action(fn ($records) => $records->each->update(['active' => false])),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentMethods::route('/'),
            'create' => Pages\CreatePaymentMethod::route('/create'),
            'edit' => Pages\EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
