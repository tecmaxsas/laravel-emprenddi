<?php

namespace App\Filament\App\Resources\Billiards;

use App\Filament\App\Resources\Billiards\BilliardTableTypeResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Billiards\BilliardTableType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Tipos de mesa: pool, tres bandas, mini pool, rana, ping pong.
 *
 * Es lo que separa las tarifas. En un salón colombiano la hora de tres
 * bandas vale casi el doble que la de pool.
 */
class BilliardTableTypeResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'billiards.manage';
    }

    protected static function requiredModule(): ?string
    {
        return 'billiards';
    }

    protected static ?string $model = BilliardTableType::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Tipos de mesa';

    protected static ?string $modelLabel = 'Tipo de mesa';

    protected static ?string $pluralModelLabel = 'Tipos de mesa';

    protected static ?string $navigationGroup = 'Billares';

    protected static ?int $navigationSort = 31;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\TextInput::make('code')->label('Código')->required()
                ->maxLength(30)->placeholder('POOL'),

            Forms\Components\TextInput::make('name')->label('Nombre')->required()
                ->maxLength(80)->placeholder('Pool'),

            Forms\Components\TextInput::make('icon')->label('Ícono')
                ->maxLength(50)->placeholder('🎱')
                ->helperText('Un emoji. Es lo que se ve en el tile del mapa del salón.'),

            Forms\Components\TextInput::make('sort_order')->label('Orden')
                ->numeric()->default(0),

            Forms\Components\Toggle::make('active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            Tables\Columns\TextColumn::make('icon')->label(''),
            Tables\Columns\TextColumn::make('code')->label('Código')->fontFamily('mono')->searchable(),
            Tables\Columns\TextColumn::make('name')->label('Nombre')->searchable()->weight('semibold'),
            Tables\Columns\TextColumn::make('tables_count')->label('Mesas')->counts('tables'),
            Tables\Columns\IconColumn::make('active')->label('Activo')->boolean(),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            Tables\Actions\DeleteBulkAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBilliardTableTypes::route('/'),
            'create' => Pages\CreateBilliardTableType::route('/create'),
            'edit' => Pages\EditBilliardTableType::route('/{record}/edit'),
        ];
    }
}
