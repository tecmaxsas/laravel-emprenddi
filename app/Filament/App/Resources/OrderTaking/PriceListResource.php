<?php

namespace App\Filament\App\Resources\OrderTaking;

use App\Filament\App\Resources\OrderTaking\PriceListResource\Pages;
use App\Filament\App\Resources\OrderTaking\PriceListResource\RelationManagers;
use App\Filament\Concerns\ChecksPermission;
use App\Models\OrderTaking\PriceList;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PriceListResource extends Resource
{
    use ChecksPermission {
        canAccess as protected permissionCanAccess;
    }

    protected static function viewPermission(): string { return 'order_taking.use'; }
    protected static function managePermission(): string { return 'order_taking.manage'; }

    protected static ?string $model = PriceList::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Listas de precios';
    protected static ?string $modelLabel = 'Lista de precios';
    protected static ?string $pluralModelLabel = 'Listas de precios';
    protected static ?string $navigationGroup = 'Toma pedidos';
    protected static ?int $navigationSort = 20;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('order_taking')) return false;
        return static::permissionCanAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos de la lista')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')->required()->maxLength(150)
                        ->placeholder('Lista 1 · Mayorista · Distribuidor · etc.'),
                    Forms\Components\TextInput::make('code')
                        ->label('Código interno')->maxLength(30)
                        ->placeholder('L1, L2, ...')
                        ->helperText('Opcional. Útil para referencias en importadores.'),
                    Forms\Components\DatePicker::make('valid_from')->label('Vigente desde')->native(false),
                    Forms\Components\DatePicker::make('valid_to')->label('Vigente hasta')->native(false),
                    Forms\Components\Toggle::make('active')->label('Activa')->default(true),
                    Forms\Components\Textarea::make('notes')->label('Notas')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable()->weight('semibold'),
            Tables\Columns\TextColumn::make('code')->label('Código')->fontFamily('mono')->toggleable(),
            Tables\Columns\TextColumn::make('items_count')
                ->label('Productos')->counts('items')->badge()->color('info'),
            Tables\Columns\TextColumn::make('valid_from')->label('Vigente desde')->date('Y-m-d')->toggleable(),
            Tables\Columns\TextColumn::make('valid_to')->label('Vigente hasta')->date('Y-m-d')->toggleable(),
            Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
        ])->filters([
            Tables\Filters\TernaryFilter::make('active')->default(true),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make()->requiresConfirmation(),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceLists::route('/'),
            'create' => Pages\CreatePriceList::route('/create'),
            'edit' => Pages\EditPriceList::route('/{record}/edit'),
        ];
    }
}
