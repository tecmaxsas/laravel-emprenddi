<?php

namespace App\Filament\App\Resources\Billiards;

use App\Filament\App\Resources\Billiards\BilliardHallResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Billiards\BilliardHall;
use App\Models\Location;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Las salas de billar.
 *
 * La "Sede de facturación" no es un adorno: es de donde sale la resolución
 * DIAN y el consecutivo. Sin ella el primer cobro del salón falla, y es el
 * error que reporta todo billar recién montado.
 */
class BilliardHallResource extends Resource
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

    protected static ?string $model = BilliardHall::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Salas';

    protected static ?string $modelLabel = 'Sala de billar';

    protected static ?string $pluralModelLabel = 'Salas de billar';

    protected static ?string $navigationGroup = 'Billares';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Código')->required()->maxLength(30)
                        ->placeholder('SALON-1'),

                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')->required()->maxLength(150)
                        ->placeholder('Salón principal'),

                    Forms\Components\TextInput::make('address')->label('Dirección')->maxLength(255),
                    Forms\Components\TextInput::make('city')->label('Ciudad')->maxLength(100),
                    Forms\Components\TextInput::make('phone')->label('Teléfono')->maxLength(30),

                    Forms\Components\Toggle::make('active')->label('Activa')->default(true),
                ]),

            Forms\Components\Section::make('Facturación')
                ->description('De aquí salen la resolución DIAN y el consecutivo de cada cobro.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('default_location_id')
                        ->label('Sede de facturación')
                        ->native(false)->searchable()->required()
                        ->options(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderByDesc('is_main')->orderBy('name')
                            ->pluck('name', 'id')->all())
                        ->helperText('Sin sede no se puede facturar: no hay de dónde sacar el consecutivo.'),

                    Forms\Components\Select::make('default_invoice_kind')
                        ->label('Tipo de documento por defecto')
                        ->native(false)
                        ->options([
                            'pos' => 'POS (no electrónica)',
                            'electronic' => 'Electrónica DIAN',
                        ])
                        ->default('pos')
                        ->selectablePlaceholder(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            Tables\Columns\TextColumn::make('code')->label('Código')->fontFamily('mono')->searchable(),
            Tables\Columns\TextColumn::make('name')->label('Sala')->searchable()->weight('semibold'),
            Tables\Columns\TextColumn::make('city')->label('Ciudad')->toggleable(),
            Tables\Columns\TextColumn::make('defaultLocation.name')->label('Sede de facturación')
                ->placeholder('Sin asignar')
                ->color(fn ($state) => $state ? null : 'danger'),
            Tables\Columns\TextColumn::make('tables_count')->label('Mesas')->counts('tables'),
            Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            Tables\Actions\DeleteBulkAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBilliardHalls::route('/'),
            'create' => Pages\CreateBilliardHall::route('/create'),
            'edit' => Pages\EditBilliardHall::route('/{record}/edit'),
        ];
    }
}
