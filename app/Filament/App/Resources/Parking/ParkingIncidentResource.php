<?php

namespace App\Filament\App\Resources\Parking;

use App\Filament\App\Resources\Parking\ParkingIncidentResource\Pages;
use App\Filament\Concerns\ChecksPermission;
use App\Models\Parking\ParkingIncident;
use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingSession;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ParkingIncidentResource extends Resource
{
    use ChecksPermission {
        canAccess as protected permissionCanAccess;
    }

    protected static function viewPermission(): string { return 'parking.incidents'; }
    protected static function managePermission(): string { return 'parking.incidents'; }

    protected static ?string $model = ParkingIncident::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationLabel = 'Incidentes';
    protected static ?string $modelLabel = 'Incidente';
    protected static ?string $pluralModelLabel = 'Incidentes';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 60;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return static::permissionCanAccess();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! ModuleGate::active('parking')) return null;
        $count = ParkingIncident::query()->open()->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos del incidente')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('parking_lot_id')
                        ->label('Parqueadero')->required()->native(false)->searchable()
                        ->options(fn () => ParkingLot::query()->where('active', true)
                            ->orderBy('name')->pluck('name', 'id')->all()),

                    Forms\Components\Select::make('kind')
                        ->label('Tipo')->required()->native(false)
                        ->options(ParkingIncident::KINDS)
                        ->default(ParkingIncident::KIND_DAMAGE),

                    Forms\Components\Select::make('severity')
                        ->label('Severidad')->required()->native(false)
                        ->options(ParkingIncident::SEVERITIES)
                        ->default(ParkingIncident::SEVERITY_MEDIUM),

                    Forms\Components\TextInput::make('plate')
                        ->label('Placa (si aplica)')->maxLength(20)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase; font-family: ui-monospace, monospace;']),

                    Forms\Components\Select::make('parking_session_id')
                        ->label('Sesión asociada (opcional)')->native(false)->searchable()
                        ->placeholder('Sin sesión')
                        ->getSearchResultsUsing(fn (string $search) => ParkingSession::query()
                            ->where('plate', 'ilike', "%{$search}%")
                            ->orderByDesc('entry_at')->limit(20)->get()
                            ->mapWithKeys(fn (ParkingSession $s) => [
                                $s->id => "#{$s->id} · {$s->plate} · ".$s->entry_at?->format('Y-m-d H:i'),
                            ])
                            ->all())
                        ->getOptionLabelUsing(fn ($v) => optional(ParkingSession::find($v))->plate)
                        ->columnSpan(2),

                    Forms\Components\Textarea::make('description')
                        ->label('Descripción')->required()->rows(3)->columnSpanFull(),

                    Forms\Components\FileUpload::make('photo_paths')
                        ->label('Fotos / evidencia')
                        ->multiple()->reorderable()
                        ->image()->imageEditor()
                        ->disk('public')
                        ->directory('parking/incidents/'.now()->format('Y/m'))
                        ->maxSize(5120)
                        ->panelLayout('grid')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Resolución')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Estado')->required()->native(false)->live()
                        ->options(ParkingIncident::STATUSES)
                        ->default(ParkingIncident::STATUS_OPEN),

                    Forms\Components\DateTimePicker::make('resolved_at')
                        ->label('Resuelto el')
                        ->visible(fn (Forms\Get $get) => in_array(
                            $get('status'),
                            [ParkingIncident::STATUS_RESOLVED, ParkingIncident::STATUS_DISMISSED],
                            true,
                        )),

                    Forms\Components\Textarea::make('resolved_notes')
                        ->label('Notas de cierre')->rows(2)
                        ->columnSpanFull()
                        ->visible(fn (Forms\Get $get) => in_array(
                            $get('status'),
                            [ParkingIncident::STATUS_RESOLVED, ParkingIncident::STATUS_DISMISSED],
                            true,
                        )),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('created_at')->label('Reportado')->dateTime('Y-m-d H:i')->sortable(),
            Tables\Columns\TextColumn::make('parkingLot.name')->label('Parqueadero')->toggleable(),
            Tables\Columns\TextColumn::make('kind')->label('Tipo')->badge()
                ->formatStateUsing(fn (string $state) => ParkingIncident::KINDS[$state] ?? $state),
            Tables\Columns\TextColumn::make('severity')->label('Severidad')->badge()
                ->formatStateUsing(fn (string $state) => ParkingIncident::SEVERITIES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    ParkingIncident::SEVERITY_LOW => 'gray',
                    ParkingIncident::SEVERITY_MEDIUM => 'info',
                    ParkingIncident::SEVERITY_HIGH => 'warning',
                    ParkingIncident::SEVERITY_CRITICAL => 'danger',
                    default => 'gray',
                }),
            Tables\Columns\TextColumn::make('plate')->label('Placa')->fontFamily('mono')->searchable(),
            Tables\Columns\TextColumn::make('description')->label('Descripción')->limit(50)->wrap(),
            Tables\Columns\TextColumn::make('status')->label('Estado')->badge()
                ->formatStateUsing(fn (string $state) => ParkingIncident::STATUSES[$state] ?? $state)
                ->color(fn (string $state) => match ($state) {
                    ParkingIncident::STATUS_OPEN => 'danger',
                    ParkingIncident::STATUS_INVESTIGATING => 'warning',
                    ParkingIncident::STATUS_RESOLVED => 'success',
                    ParkingIncident::STATUS_DISMISSED => 'gray',
                    default => 'gray',
                }),
            Tables\Columns\TextColumn::make('reportedBy.name')->label('Reportado por')->toggleable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(ParkingIncident::STATUSES),
            Tables\Filters\SelectFilter::make('kind')->options(ParkingIncident::KINDS),
            Tables\Filters\SelectFilter::make('severity')->options(ParkingIncident::SEVERITIES),
            Tables\Filters\SelectFilter::make('parking_lot_id')->label('Parqueadero')
                ->options(fn () => ParkingLot::query()->orderBy('name')->pluck('name', 'id')->all()),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListParkingIncidents::route('/'),
            'create' => Pages\CreateParkingIncident::route('/create'),
            'edit' => Pages\EditParkingIncident::route('/{record}/edit'),
        ];
    }
}
