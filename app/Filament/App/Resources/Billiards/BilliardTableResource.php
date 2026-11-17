<?php

namespace App\Filament\App\Resources\Billiards;

use App\Filament\App\Resources\Billiards\BilliardTableResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Billiards\BilliardHall;
use App\Models\Billiards\BilliardTable;
use App\Models\Billiards\BilliardTableType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Las mesas del salón. Cada una es un tile del mapa del terminal.
 */
class BilliardTableResource extends Resource
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

    protected static ?string $model = BilliardTable::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Mesas';

    protected static ?string $modelLabel = 'Mesa';

    protected static ?string $pluralModelLabel = 'Mesas';

    protected static ?string $navigationGroup = 'Billares';

    protected static ?int $navigationSort = 32;

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            Forms\Components\Select::make('hall_id')
                ->label('Sala')->required()->native(false)->searchable()
                ->options(fn () => BilliardHall::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->where('active', true)
                    ->orderBy('name')->pluck('name', 'id')->all()),

            Forms\Components\Select::make('table_type_id')
                ->label('Tipo de mesa')->native(false)->searchable()
                ->placeholder('Sin tipo')
                ->options(fn () => BilliardTableType::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->where('active', true)
                    ->orderBy('sort_order')->pluck('name', 'id')->all())
                ->helperText('Decide qué tarifa le aplica.'),

            Forms\Components\TextInput::make('code')->label('Número / código')->required()
                ->maxLength(30)->placeholder('1, VIP-2'),

            Forms\Components\TextInput::make('zone')->label('Zona')
                ->maxLength(50)->placeholder('Salón principal, Terraza, Segundo piso')
                ->helperText('Agrupa los tiles del mapa.'),

            Forms\Components\Select::make('status')->label('Estado')->required()->native(false)
                ->options(BilliardTable::STATUSES)
                ->default(BilliardTable::STATUS_FREE)
                ->helperText('«Mantenimiento» saca la mesa del mapa sin borrarla.'),

            Forms\Components\TextInput::make('sort_order')->label('Orden en el mapa')
                ->numeric()->default(0),

            Forms\Components\Toggle::make('active')->label('Activa')->default(true),

            Forms\Components\Textarea::make('notes')->label('Notas')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')->columns([
            Tables\Columns\TextColumn::make('hall.name')->label('Sala')->searchable(),
            Tables\Columns\TextColumn::make('zone')->label('Zona')->searchable()->toggleable(),
            Tables\Columns\TextColumn::make('code')->label('Mesa')->fontFamily('mono')
                ->searchable()->weight('semibold'),
            Tables\Columns\TextColumn::make('tableType.name')->label('Tipo')->placeholder('Sin tipo'),
            Tables\Columns\TextColumn::make('status')->label('Estado')->badge()
                ->formatStateUsing(fn (string $state) => BilliardTable::STATUSES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    BilliardTable::STATUS_FREE => 'success',
                    BilliardTable::STATUS_OCCUPIED => 'danger',
                    BilliardTable::STATUS_RESERVED => 'warning',
                    default => 'gray',
                }),
            Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('hall_id')->label('Sala')
                ->options(fn () => BilliardHall::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->orderBy('name')->pluck('name', 'id')->all()),
            Tables\Filters\SelectFilter::make('status')->label('Estado')
                ->options(BilliardTable::STATUSES),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            Tables\Actions\DeleteBulkAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBilliardTables::route('/'),
            'create' => Pages\CreateBilliardTable::route('/create'),
            'edit' => Pages\EditBilliardTable::route('/{record}/edit'),
        ];
    }
}
