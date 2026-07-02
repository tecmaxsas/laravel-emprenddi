<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ExpenseResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\Location;
use App\Models\Tax;
use App\Models\ThirdParty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpenseResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'purchases.view'; }
    protected static function managePermission(): string { return 'purchases.create'; }

    protected static ?string $model = Expense::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Gastos';

    protected static ?string $modelLabel = 'Gasto';

    protected static ?string $pluralModelLabel = 'Gastos';

    protected static ?string $navigationGroup = 'Compras';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos del gasto')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('prefix')
                        ->label('Prefijo')
                        ->default('EXP')
                        ->maxLength(10)
                        ->required(),

                    Forms\Components\TextInput::make('number')
                        ->label('Número')
                        ->numeric()
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Auto al guardar'),

                    Forms\Components\DatePicker::make('date')
                        ->label('Fecha')
                        ->required()
                        ->default(now()),

                    Forms\Components\Select::make('location_id')
                        ->label('Sede')
                        ->required()
                        ->options(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Location $l) => [$l->id => $l->fullName()])
                            ->all())
                        ->default(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_main', true)
                            ->value('id'))
                        ->native(false),

                    Forms\Components\TextInput::make('concept')
                        ->label('Concepto')
                        ->required()
                        ->maxLength(200)
                        ->placeholder('Ej. Café para la oficina')
                        ->columnSpan(2),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Proveedor (opcional)')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->where(function ($q) use ($search) {
                                $q->where('document_number', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('name')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (ThirdParty $t) => [$t->id => "{$t->document_number} — {$t->name}"])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => ThirdParty::query()->where('company_id', auth()->user()?->company_id)->find($value)
                            ? ThirdParty::query()->where('company_id', auth()->user()?->company_id)->find($value)->document_number.' — '.ThirdParty::query()->where('company_id', auth()->user()?->company_id)->find($value)->name
                            : null)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('supplier_invoice_number')
                        ->label('N° factura proveedor')
                        ->maxLength(50)
                        ->placeholder('FV-12345')
                        ->columnSpan(2),

                    Forms\Components\Textarea::make('description')
                        ->label('Descripción detallada')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Imputación contable')
                ->description('Cuenta donde se registra el gasto (DR) y la cuenta de donde sale el dinero (CR).')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('expense_account_id')
                        ->label('Cuenta de gasto (DR)')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('type', 'gasto')
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => $a->fullName()])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Account::query()->where('company_id', auth()->user()?->company_id)->find($value)?->fullName())
                        ->helperText('Cuenta clase 5 (p. ej. 5135 Servicios, 5145 Mantenimiento)'),

                    Forms\Components\Select::make('cost_center_id')
                        ->label('Centro de costo (opcional)')
                        ->searchable()
                        ->placeholder('Sin centro de costo')
                        ->getSearchResultsUsing(fn (string $search) => CostCenter::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->where('accepts_movements', true)
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (CostCenter $c) => [$c->id => $c->fullName()])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => CostCenter::query()->where('company_id', auth()->user()?->company_id)->find($value)?->fullName())
                        ->helperText('Para reportes de gastos por área / sucursal / proyecto.')
                        ->columnSpanFull(),

                    Forms\Components\Select::make('payment_account_id')
                        ->label('Cuenta de pago (CR)')
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('type', 'activo')
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where('code', 'like', '11%')
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => $a->fullName()])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Account::query()->where('company_id', auth()->user()?->company_id)->find($value)?->fullName())
                        ->helperText('Caja (1105) o banco (1110) de donde sale el dinero.'),
                ]),

            Forms\Components\Section::make('Montos y pago')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('subtotal')
                        ->label('Subtotal')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('$')
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeTotals($set, $get)),

                    Forms\Components\Select::make('tax_id')
                        ->label('IVA (opcional)')
                        ->live()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Tax::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_active', true)
                            ->whereIn('applies_to', ['purchase', 'both'])
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                  ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Tax $t) => [$t->id => "{$t->code} ({$t->rate}%)"])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => Tax::query()->where('company_id', auth()->user()?->company_id)->find($value)
                            ? Tax::query()->where('company_id', auth()->user()?->company_id)->find($value)->code.' ('.Tax::query()->where('company_id', auth()->user()?->company_id)->find($value)->rate.'%)'
                            : null)
                        ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get) => self::recomputeTotals($set, $get)),

                    Forms\Components\TextInput::make('tax_amount')
                        ->label('IVA $')
                        ->numeric()
                        ->prefix('$')
                        ->disabled()
                        ->dehydrated()
                        ->default(0),

                    Forms\Components\TextInput::make('total')
                        ->label('Total')
                        ->numeric()
                        ->prefix('$')
                        ->disabled()
                        ->dehydrated()
                        ->default(0),

                    Forms\Components\Select::make('payment_method')
                        ->label('Método de pago')
                        ->required()
                        ->options([
                            'cash' => 'Efectivo',
                            'bank_transfer' => 'Transferencia bancaria',
                            'check' => 'Cheque',
                            'credit_card' => 'Tarjeta de crédito',
                            'debit_card' => 'Tarjeta débito',
                            'electronic' => 'PSE / Pago electrónico',
                            'other' => 'Otro',
                        ])
                        ->default('cash')
                        ->native(false)
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('reference')
                        ->label('Referencia (cheque, comprobante)')
                        ->maxLength(100)
                        ->columnSpan(2),

                    Forms\Components\Hidden::make('tax_rate')->default(0),
                ]),

            Forms\Components\Textarea::make('notes')
                ->label('Notas internas')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Recalcula tax_amount y total a partir de subtotal + tax_id.
     */
    protected static function recomputeTotals(Forms\Set $set, Forms\Get $get): void
    {
        $subtotal = (float) ($get('subtotal') ?? 0);
        $taxRate = 0;
        $taxAmount = 0;

        if ($taxId = $get('tax_id')) {
            $tax = Tax::find($taxId);
            if ($tax) {
                $taxRate = (float) $tax->rate;
                $taxAmount = round($subtotal * ($taxRate / 100), 2);
            }
        }

        $set('tax_rate', $taxRate);
        $set('tax_amount', $taxAmount);
        $set('total', round($subtotal + $taxAmount, 2));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('full_number')
                    ->label('Número')
                    ->state(fn (Expense $record) => $record->fullNumber())
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('number', 'like', "%{$search}%")
                        ->orWhere('supplier_invoice_number', 'ilike', "%{$search}%")),

                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),

                Tables\Columns\TextColumn::make('concept')
                    ->label('Concepto')
                    ->searchable()
                    ->wrap()
                    ->limit(60),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Proveedor')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('expenseAccount.code')
                    ->label('Cuenta')
                    ->state(fn (Expense $record) => $record->expenseAccount?->fullName())
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('COP')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Pago')
                    ->formatStateUsing(fn (string $state) => [
                        'cash' => 'Efectivo',
                        'bank_transfer' => 'Transferencia',
                        'check' => 'Cheque',
                        'credit_card' => 'T. Crédito',
                        'debit_card' => 'T. Débito',
                        'electronic' => 'PSE',
                        'other' => 'Otro',
                    ][$state] ?? $state)
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state) => Expense::STATUSES[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'posted' => 'success',
                        'cancelled' => 'danger',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(Expense::STATUSES),
                Tables\Filters\Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Desde'),
                        Forms\Components\DatePicker::make('to')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
                            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()->visible(fn (Expense $record) => $record->status === 'draft'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenses::route('/'),
            'create' => Pages\CreateExpense::route('/create'),
            'view' => Pages\ViewExpense::route('/{record}'),
            'edit' => Pages\EditExpense::route('/{record}/edit'),
        ];
    }
}
