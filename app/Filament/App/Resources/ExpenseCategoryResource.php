<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ExpenseCategoryResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Account;
use App\Models\ExpenseCategory;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * En qué se le va la plata al negocio.
 *
 * La categoría es la clasificación del negocio; la cuenta contable del PUC
 * sigue siendo la fiscal. Varias categorías pueden ir a la misma cuenta.
 *
 * Se administra con los mismos permisos que los gastos: quien puede registrar
 * un gasto puede crear la categoría donde va, porque si no, cada categoría
 * nueva sería una solicitud al administrador.
 */
class ExpenseCategoryResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'expenses.view';
    }

    protected static function managePermission(): string
    {
        return 'expenses.create';
    }

    protected static ?string $model = ExpenseCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Categorías de gasto';

    protected static ?string $modelLabel = 'Categoría de gasto';

    protected static ?string $pluralModelLabel = 'Categorías de gasto';

    protected static ?string $navigationGroup = 'Gastos';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Categoría')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(120)
                        ->placeholder('Ej. Transporte y domicilios')
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule->where('company_id', auth()->user()?->company_id),
                        )
                        ->helperText('Dos categorías con el mismo nombre parten en dos un renglón del reporte.')
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('description')
                        ->label('Descripción (opcional)')
                        ->maxLength(255)
                        ->placeholder('Qué entra y qué no entra en esta categoría')
                        ->columnSpan(2),

                    // Solo tiene sentido si la empresa lleva libros.
                    Forms\Components\Select::make('default_expense_account_id')
                        ->label('Cuenta contable por defecto')
                        ->visible(fn () => ModuleGate::active(ModuleGate::ACCOUNTING))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('accepts_movements', true)
                            ->where('code', 'like', '5%')
                            ->where(function (Builder $q) use ($search) {
                                $q->where('code', 'ilike', "%{$search}%")
                                    ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                            ->all())
                        ->getOptionLabelUsing(function ($value) {
                            $cuenta = Account::query()->find($value);

                            return $cuenta ? "{$cuenta->code} — {$cuenta->name}" : null;
                        })
                        ->helperText('Al elegir esta categoría en un gasto, la imputación contable queda resuelta sola. Quien registra el gasto no tiene que saberse el PUC.')
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('sort_order')
                        ->label('Orden en la lista')
                        ->numeric()
                        ->default(999)
                        ->helperText('Las más usadas de primero.'),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')
                        ->default(true)
                        ->helperText('Una categoría desactivada deja de ofrecerse, pero los gastos que ya la usan la conservan y el histórico no cambia.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderBy('sort_order')->orderBy('name'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Descripción')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('defaultExpenseAccount.code')
                    ->label('Cuenta por defecto')
                    ->state(fn (ExpenseCategory $r) => $r->defaultExpenseAccount
                        ? $r->defaultExpenseAccount->code.' — '.$r->defaultExpenseAccount->name
                        : null)
                    ->placeholder('—')
                    ->visible(fn () => ModuleGate::active(ModuleGate::ACCOUNTING))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('expenses_count')
                    ->label('Gastos')
                    ->counts('expenses')
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activa')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activa'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Sin DeleteAction: borrar una categoria que ya tiene gastos
                // deja el historico sin clasificar. Para dejar de usarla esta
                // el interruptor de «Activa», que no toca lo ya registrado.
            ])
            ->emptyStateHeading('Todavía no hay categorías de gasto')
            ->emptyStateDescription('Sirven para saber en qué se está yendo la plata: arriendo, domicilios, mantenimiento.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenseCategories::route('/'),
            'create' => Pages\CreateExpenseCategory::route('/create'),
            'edit' => Pages\EditExpenseCategory::route('/{record}/edit'),
        ];
    }
}
