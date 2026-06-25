<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\PosResolutionResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Dian\Resolution;
use App\Models\Location;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resoluciones POS — numeración local de facturas POS (documento
 * equivalente). NO se transmiten a la DIAN. Se asignan a una o varias
 * sedes; cada sede lleva su propio consecutivo dentro del rango.
 *
 * Comparten tabla con las resoluciones electrónicas (dian_resolutions)
 * pero el resource scopea kind='pos'.
 */
class PosResolutionResource extends Resource
{
    use ChecksPermission;

    protected static function viewPermission(): string { return 'pos.resolutions.manage'; }
    protected static function managePermission(): string { return 'pos.resolutions.manage'; }

    protected static ?string $model = Resolution::class;

    protected static ?string $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $navigationLabel = 'Resoluciones POS';

    protected static ?string $modelLabel = 'Resolución POS';

    protected static ?string $pluralModelLabel = 'Resoluciones POS';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 35;

    protected static ?string $slug = 'pos-resolutions';

    /**
     * El resource solo trabaja con resoluciones kind='pos'.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('kind', Resolution::KIND_POS);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos de la resolución POS')
                ->description('La numeración POS es local — no se envía a la DIAN. Cargá acá la resolución de numeración del documento equivalente POS.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('prefix')
                        ->label('Prefijo')
                        ->required()
                        ->maxLength(10)
                        ->placeholder('POS')
                        ->helperText('Aparece antes del número: POS-000001.'),

                    Forms\Components\TextInput::make('resolution_number')
                        ->label('Número de resolución')
                        ->maxLength(50)
                        ->placeholder('18764000000000'),

                    Forms\Components\DatePicker::make('resolution_date')
                        ->label('Fecha de la resolución')
                        ->displayFormat('d/m/Y'),

                    Forms\Components\TextInput::make('range_from')
                        ->label('Rango desde')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->default(1),

                    Forms\Components\TextInput::make('range_to')
                        ->label('Rango hasta')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->gt('range_from'),

                    Forms\Components\Toggle::make('active')
                        ->label('Activa')
                        ->default(true)
                        ->inline(false),

                    Forms\Components\DatePicker::make('date_from')
                        ->label('Vigencia desde')
                        ->displayFormat('d/m/Y'),

                    Forms\Components\DatePicker::make('date_to')
                        ->label('Vigencia hasta')
                        ->displayFormat('d/m/Y')
                        ->after('date_from'),
                ]),

            Forms\Components\Section::make('Asignación a sedes')
                ->description('Asigná esta resolución a las sedes que la usarán. Cada sede arranca con su propio consecutivo dentro del rango.')
                ->schema([
                    Forms\Components\Repeater::make('locationAssignments')
                        ->label('')
                        ->relationship()
                        ->addActionLabel('+ Asignar a una sede')
                        ->itemLabel(fn (array $state): ?string => isset($state['location_id'])
                            ? (Location::find($state['location_id'])?->name ?? 'Sede')
                            : 'Nueva asignación')
                        ->columns(3)
                        ->schema([
                            Forms\Components\Select::make('location_id')
                                ->label('Sede')
                                ->required()
                                ->options(fn () => Location::query()
                                    ->where('company_id', auth()->user()?->company_id)
                                    ->where('active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all())
                                ->native(false)
                                ->distinct()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),

                            Forms\Components\TextInput::make('current_consecutive')
                                ->label('Próximo consecutivo')
                                ->required()
                                ->numeric()
                                ->minValue(1)
                                ->default(1)
                                ->helperText('Número con el que arranca esta sede.'),

                            Forms\Components\Toggle::make('active')
                                ->label('Activa')
                                ->default(true)
                                ->inline(false),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('prefix')
                    ->label('Prefijo')
                    ->weight('semibold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('resolution_number')
                    ->label('Nº resolución')
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('range')
                    ->label('Rango')
                    ->state(fn (Resolution $r) => number_format($r->range_from).' – '.number_format($r->range_to)),

                Tables\Columns\TextColumn::make('date_to')
                    ->label('Vigente hasta')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('location_assignments_count')
                    ->label('Sedes')
                    ->counts('locationAssignments')
                    ->alignCenter()
                    ->badge()
                    ->color('info'),

                Tables\Columns\IconColumn::make('active')->label('Activa')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')->label('Activa')->default(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPosResolutions::route('/'),
            'create' => Pages\CreatePosResolution::route('/create'),
            'edit' => Pages\EditPosResolution::route('/{record}/edit'),
        ];
    }
}
