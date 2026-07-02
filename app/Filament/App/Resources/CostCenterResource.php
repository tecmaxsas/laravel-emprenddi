<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\CostCenterResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\CostCenter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CostCenterResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'accounts.view'; }
    protected static function managePermission(): string { return 'accounts.manage'; }

    protected static ?string $model = CostCenter::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Centros de costo';

    protected static ?string $modelLabel = 'Centro de costo';

    protected static ?string $pluralModelLabel = 'Centros de costo';

    protected static ?string $navigationGroup = 'Configuración contable';

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('code')
                ->label('Código')
                ->required()
                ->maxLength(20)
                ->placeholder('Ej. CC-001, ADMIN, VENTAS')
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule) => $rule->where('company_id', auth()->user()->company_id)
                ),

            Forms\Components\TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(150)
                ->placeholder('Ej. Administración'),

            Forms\Components\Select::make('parent_id')
                ->label('Padre (opcional)')
                ->placeholder('Sin padre — centro raíz')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => CostCenter::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->where(function ($q) use ($search) {
                        $q->where('code', 'ilike', "%{$search}%")
                          ->orWhere('name', 'ilike', "%{$search}%");
                    })
                    ->orderBy('code')
                    ->limit(30)
                    ->get()
                    ->mapWithKeys(fn (CostCenter $c) => [$c->id => $c->fullName()])
                    ->all())
                ->getOptionLabelUsing(fn ($value) => CostCenter::find($value)?->fullName())
                ->helperText('Para agrupar jerárquicamente. Vacío = centro raíz.'),

            Forms\Components\Toggle::make('accepts_movements')
                ->label('Acepta imputación de movimientos')
                ->helperText('Desactívalo para centros agrupadores (solo tienen hijos).')
                ->default(true)
                ->inline(false),

            Forms\Components\Toggle::make('active')
                ->label('Activo')
                ->default(true)
                ->inline(false),

            Forms\Components\Textarea::make('description')
                ->label('Descripción')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->wrap()
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('parent.code')
                    ->label('Padre')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('accepts_movements')->label('Imputable')->boolean(),
                Tables\Columns\IconColumn::make('active')->label('Activo')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activo')->default(true),
                Tables\Filters\TernaryFilter::make('accepts_movements')->label('Imputable'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCostCenters::route('/'),
            'create' => Pages\CreateCostCenter::route('/create'),
            'edit' => Pages\EditCostCenter::route('/{record}/edit'),
        ];
    }
}
