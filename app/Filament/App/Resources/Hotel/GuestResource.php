<?php

namespace App\Filament\App\Resources\Hotel;

use App\Filament\App\Resources\Hotel\GuestResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Hotel\Guest;
use App\Models\ThirdParty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Huéspedes: el registro de quién se alojó.
 *
 * Es la tarjeta de registro hotelero, no el maestro de clientes. Los datos que
 * pide —nacionalidad, procedencia, destino, motivo del viaje— no le sirven a
 * la contabilidad y por eso no viven en Terceros; `third_party_id` enlaza los
 * dos cuando el huésped además es quien paga.
 */
class GuestResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string
    {
        return 'hotel.guests.manage';
    }

    protected static function requiredModule(): ?string
    {
        return 'hotel';
    }

    protected static ?string $model = Guest::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Huéspedes';

    protected static ?string $modelLabel = 'Huésped';

    protected static ?string $pluralModelLabel = 'Huéspedes';

    protected static ?string $navigationGroup = 'Hotel';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identificación')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('first_name')
                        ->label('Nombres')
                        ->required()
                        ->maxLength(80),

                    Forms\Components\TextInput::make('last_name')
                        ->label('Apellidos')
                        ->maxLength(80)
                        ->helperText('Aparte del nombre: así lo pide la tarjeta de registro.'),

                    Forms\Components\Select::make('document_type')
                        ->label('Tipo de documento')
                        ->native(false)
                        ->options([
                            'cc' => 'Cédula de ciudadanía',
                            'ce' => 'Cédula de extranjería',
                            'pa' => 'Pasaporte',
                            'ti' => 'Tarjeta de identidad',
                            'rc' => 'Registro civil',
                            'pep' => 'PEP / PPT',
                        ])
                        ->default('cc'),

                    Forms\Components\TextInput::make('document_number')
                        ->label('Número de documento')
                        ->maxLength(30)
                        ->helperText('Puede quedar vacío para un menor de edad que viaja con un acompañante.'),

                    Forms\Components\DatePicker::make('birth_date')
                        ->label('Fecha de nacimiento')
                        ->maxDate(now()),

                    Forms\Components\Select::make('gender')
                        ->label('Género')
                        ->native(false)
                        ->options([
                            'f' => 'Femenino',
                            'm' => 'Masculino',
                            'o' => 'Otro',
                            'n' => 'Prefiere no decirlo',
                        ]),

                    Forms\Components\TextInput::make('nationality')
                        ->label('Nacionalidad')
                        ->maxLength(60)
                        ->placeholder('Colombiana')
                        ->helperText('Si no es colombiana, el hospedaje puede ir exento de IVA '
                            .'(art. 481 ET) y hay que poder reportarlo.'),
                ]),

            Forms\Components\Section::make('Contacto')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('phone')->label('Teléfono')->tel()->maxLength(30),
                    Forms\Components\TextInput::make('email')->label('Correo')->email()->maxLength(150),
                    Forms\Components\TextInput::make('address')->label('Dirección')->maxLength(255),
                    Forms\Components\TextInput::make('city')->label('Ciudad de residencia')->maxLength(100),
                ]),

            Forms\Components\Section::make('Datos del viaje')
                ->description('Los tres campos del registro hotelero que ningún otro módulo pide.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('origin')
                        ->label('Procedencia')
                        ->maxLength(120)
                        ->placeholder('De dónde viene'),

                    Forms\Components\TextInput::make('destination')
                        ->label('Destino')
                        ->maxLength(120)
                        ->placeholder('Para dónde sigue'),

                    Forms\Components\Select::make('travel_purpose')
                        ->label('Motivo del viaje')
                        ->native(false)
                        ->options(Guest::PURPOSES),
                ]),

            Forms\Components\Section::make('Enlace con el maestro de clientes')
                ->description('Solo si este huésped también es quien paga: así el check-out factura a su '
                    .'nombre sin volver a digitar nada.')
                ->schema([
                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente / tercero')
                        ->native(false)
                        ->searchable()
                        ->placeholder('Sin enlazar')
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_customer', true)
                            ->where(fn ($q) => $q
                                ->where('name', 'ilike', "%{$search}%")
                                ->orWhere('document_number', 'ilike', "%{$search}%"))
                            ->orderBy('name')
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (ThirdParty $t) => [$t->id => ($t->document_number ?: '—').' · '.$t->name])
                            ->all())
                        ->getOptionLabelUsing(fn ($value) => ThirdParty::find($value)?->name),

                    Forms\Components\Textarea::make('notes')->label('Notas')->rows(2),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_name')
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Huésped')
                    ->state(fn (Guest $record) => $record->nombreCompleto())
                    ->searchable(query: fn ($query, string $search) => $query
                        ->where(fn ($q) => $q
                            ->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")))
                    ->weight('semibold')
                    ->description(fn (Guest $record) => $record->documento()),

                Tables\Columns\TextColumn::make('document_number')
                    ->label('Documento')
                    ->fontFamily('mono')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('nationality')
                    ->label('Nacionalidad')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (Guest $record) => $record->esExtranjero() ? 'warning' : 'gray')
                    ->description(fn (Guest $record) => $record->esExtranjero() ? 'Extranjero' : null),

                Tables\Columns\TextColumn::make('phone')->label('Teléfono')->toggleable(),

                Tables\Columns\TextColumn::make('city')->label('Residencia')->toggleable(),

                Tables\Columns\TextColumn::make('travel_purpose')
                    ->label('Motivo')
                    ->formatStateUsing(fn (?string $state) => Guest::PURPOSES[$state] ?? '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('stays_count')
                    ->label('Estadías')
                    ->counts('stays')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('thirdParty.name')
                    ->label('Cliente enlazado')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('travel_purpose')
                    ->label('Motivo del viaje')
                    ->options(Guest::PURPOSES),

                // Mismo criterio que Guest::esExtranjero(): pasaporte o cédula
                // de extranjería ya lo dicen, y si no, la nacionalidad.
                Tables\Filters\Filter::make('extranjeros')
                    ->label('Solo extranjeros')
                    ->query(fn ($query) => $query->where(fn ($q) => $q
                        ->whereIn('document_type', ['pa', 'ce'])
                        ->orWhere(fn ($sub) => $sub
                            ->whereNotNull('nationality')
                            ->whereRaw('lower(trim(nationality)) not in (?, ?, ?, ?, ?)', [
                                'co', 'col', 'colombia', 'colombiana', 'colombiano',
                            ])))),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGuests::route('/'),
            'create' => Pages\CreateGuest::route('/create'),
            'edit' => Pages\EditGuest::route('/{record}/edit'),
        ];
    }
}
