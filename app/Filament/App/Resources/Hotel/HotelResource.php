<?php

namespace App\Filament\App\Resources\Hotel;

use App\Filament\App\Resources\Hotel\HotelResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Hotel\Hotel;
use App\Models\Location;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HotelResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'hotel.manage';
    }

    protected static function requiredModule(): ?string
    {
        return 'hotel';
    }

    protected static ?string $model = Hotel::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Hoteles';

    protected static ?string $modelLabel = 'Hotel';

    protected static ?string $pluralModelLabel = 'Hoteles';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 50;

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
                        ->placeholder('H01')
                        ->helperText('Corto: identifica el hotel en listados y reportes.'),

                    Forms\Components\TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(150),

                    Forms\Components\TextInput::make('rnt')
                        ->label('Registro Nacional de Turismo (RNT)')
                        ->maxLength(30)
                        ->helperText('Obligatorio para todo prestador de servicios turísticos en Colombia. '
                            .'Se imprime en la cuenta del huésped.'),

                    Forms\Components\Toggle::make('active')
                        ->label('Activo')
                        ->default(true),
                ]),

            Forms\Components\Section::make('Contacto')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('address')->label('Dirección')->maxLength(255),
                    Forms\Components\TextInput::make('city')->label('Ciudad')->maxLength(100),
                    Forms\Components\TextInput::make('phone')->label('Teléfono')->tel()->maxLength(30),
                    Forms\Components\TextInput::make('email')->label('Correo')->email()->maxLength(150),
                ]),

            Forms\Components\Section::make('Facturación')
                ->description('Sin sede no hay resolución ni consecutivo, y el check-out no podrá emitir factura.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('default_location_id')
                        ->label('Sede de facturación')
                        ->native(false)
                        ->searchable()
                        ->required()
                        ->options(fn () => Location::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderByDesc('is_main')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),

                    Forms\Components\Select::make('default_invoice_kind')
                        ->label('Tipo de documento por defecto')
                        ->native(false)
                        ->required()
                        ->options([
                            'pos' => 'Tiquete POS',
                            'electronic' => 'Factura electrónica (DIAN)',
                        ])
                        ->default('pos')
                        ->helperText('Lo que propone la recepción al cobrar. Se puede cambiar en cada check-out.'),
                ]),

            Forms\Components\Section::make('Horarios')
                ->description('La hora de salida no es decorativa: decide a partir de cuándo una entrega '
                    .'tardía suma una noche al folio. La holgura se configura en Configuración → Hotel.')
                ->columns(2)
                ->schema([
                    Forms\Components\TimePicker::make('check_in_time')
                        ->label('Hora de entrada')
                        ->seconds(false)
                        ->default('15:00'),

                    Forms\Components\TimePicker::make('check_out_time')
                        ->label('Hora de salida')
                        ->seconds(false)
                        ->default('12:00'),
                ]),

            Forms\Components\Textarea::make('settings.notes')
                ->label('Notas internas')
                ->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Hotel')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->description(fn (Hotel $record) => trim(($record->city ?? '').' '.($record->address ?? '')) ?: null),

                Tables\Columns\TextColumn::make('rooms_count')
                    ->label('Habitaciones')
                    ->counts('rooms')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('room_types_count')
                    ->label('Tipos')
                    ->counts('roomTypes')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('defaultLocation.name')
                    ->label('Sede de facturación')
                    ->placeholder('⚠ Sin sede')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('check_out_time')
                    ->label('Salida')
                    ->formatStateUsing(fn (Hotel $record) => $record->checkOutTime())
                    ->toggleable(),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activo'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHotels::route('/'),
            'create' => Pages\CreateHotel::route('/create'),
            'edit' => Pages\EditHotel::route('/{record}/edit'),
        ];
    }
}
