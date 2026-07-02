<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\TaxResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\Tax;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaxResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'taxes.view'; }
    protected static function managePermission(): string { return 'taxes.manage'; }

    protected static ?string $model = Tax::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Impuestos';

    protected static ?string $modelLabel = 'Impuesto';

    protected static ?string $pluralModelLabel = 'Impuestos';

    protected static ?string $navigationGroup = 'Contabilidad';

    protected static ?int $navigationSort = 30;

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
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule
                                ->where('company_id', auth()->user()->company_id)
                        ),

                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(150),

                    Forms\Components\Select::make('type')
                        ->label('Tipo')
                        ->options(Tax::TYPES)
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('rate')
                        ->label('Tarifa (%)')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.0001)
                        ->suffix('%'),

                    Forms\Components\Select::make('takeaway_tax_id')
                        ->label('Impuesto alternativo "para llevar" (restaurante)')
                        ->helperText('Solo aplica al módulo restaurante. Si una orden es para llevar/domicilio, el sistema usa este impuesto en vez del actual. Ej: INC 8% (comer aquí) → IVA 19% (para llevar). Dejar vacío si la tasa no cambia.')
                        ->options(fn ($record) => \App\Models\Tax::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_active', true)
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($t) => [$t->id => $t->code.' — '.$t->name.' ('.rtrim(rtrim((string) $t->rate, '0'), '.').'%)'])
                            ->all())
                        ->searchable()
                        ->native(false)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Cuentas contables')
                ->description('Cuentas donde se registra el impuesto según la operación.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('sale_account_id')
                        ->label('Cuenta en VENTA')
                        ->relationship(
                            'saleAccount',
                            'name',
                            fn (Builder $query) => $query->where('accepts_movements', true)->orderBy('code'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Account $record) => "{$record->code} — {$record->name}")
                        ->searchable(['code', 'name'])
                        ->preload()
                        ->placeholder('— sin cuenta —')
                        ->helperText('Para IVA: 240805 generado. Para ReteFuente cobrada: 1355...'),

                    Forms\Components\Select::make('purchase_account_id')
                        ->label('Cuenta en COMPRA')
                        ->relationship(
                            'purchaseAccount',
                            'name',
                            fn (Builder $query) => $query->where('accepts_movements', true)->orderBy('code'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Account $record) => "{$record->code} — {$record->name}")
                        ->searchable(['code', 'name'])
                        ->preload()
                        ->placeholder('— sin cuenta —')
                        ->helperText('Para IVA: 240810 descontable. Para ReteFuente que aplicamos: 2365...'),
                ]),

            Forms\Components\Section::make('Aplicación')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('applies_to')
                        ->label('Aplica a')
                        ->options(Tax::APPLIES_TO)
                        ->default('both')
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('minimum_base')
                        ->label('Base mínima (COP)')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix('$')
                        ->helperText('No aplica si la base de cálculo es menor a este valor.'),

                    Forms\Components\Toggle::make('is_default_for_sale')
                        ->label('Default en ventas'),

                    Forms\Components\Toggle::make('is_default_for_purchase')
                        ->label('Default en compras'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true),
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
            ->defaultSort('type')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => Tax::TYPES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'vat' => 'success',
                        'consumption_tax' => 'info',
                        'income_withholding' => 'warning',
                        'vat_withholding' => 'warning',
                        'ica_withholding' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('rate')
                    ->label('Tarifa')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 4, '.', '').' %')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('saleAccount.code')
                    ->label('Cta. Venta')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('purchaseAccount.code')
                    ->label('Cta. Compra')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('applies_to')
                    ->label('Aplica')
                    ->formatStateUsing(fn (string $state) => Tax::APPLIES_TO[$state] ?? $state)
                    ->badge()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('minimum_base')
                    ->label('Base mín.')
                    ->money('COP')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_default_for_sale')
                    ->label('Def. V')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_default_for_purchase')
                    ->label('Def. C')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_system')
                    ->label('Sistema')
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(Tax::TYPES),

                Tables\Filters\SelectFilter::make('applies_to')
                    ->label('Aplica a')
                    ->options(Tax::APPLIES_TO),

                Tables\Filters\TernaryFilter::make('is_active')->label('Activo')->default(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Tax $record) => ! $record->is_system),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activar')
                        ->icon('heroicon-o-check')
                        ->action(fn ($records) => $records->each->update(['is_active' => true])),

                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Desactivar')
                        ->icon('heroicon-o-x-mark')
                        ->action(fn ($records) => $records->each->update(['is_active' => false])),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaxes::route('/'),
            'create' => Pages\CreateTax::route('/create'),
            'edit' => Pages\EditTax::route('/{record}/edit'),
        ];
    }
}
