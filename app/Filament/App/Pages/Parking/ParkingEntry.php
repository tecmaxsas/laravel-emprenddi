<?php

namespace App\Filament\App\Pages\Parking;

use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingSession;
use App\Models\Parking\ParkingSpace;
use App\Models\Parking\VehicleType;
use App\Services\Parking\ParkingSessionEngine;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Pantalla de registro de entrada al parqueadero. El operario digita
 * (o escanea con lector) la placa, escoge tipo de vehículo y parqueadero,
 * y se crea una sesión activa.
 */
class ParkingEntry extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-right-end-on-rectangle';
    protected static ?string $navigationLabel = 'Entrada';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 1;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Registrar entrada';

    protected static string $view = 'filament.app.pages.parking.entry';

    public ?array $data = [];
    public ?ParkingSession $lastSession = null;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return (bool) auth()->user()?->can('parking.use');
    }

    public function mount(): void
    {
        $companyId = auth()->user()?->company_id;

        $this->data = [
            'parking_lot_id' => ParkingLot::query()
                ->where('company_id', $companyId)
                ->where('active', true)->orderBy('id')->value('id'),
            'vehicle_type_id' => VehicleType::query()
                ->where('company_id', $companyId)
                ->where('active', true)
                ->where('code', VehicleType::CODE_CAR)->value('id'),
            'parking_space_id' => null,
            'plate' => '',
            'notes' => null,
        ];
        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos del vehículo')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('parking_lot_id')
                        ->label('Parqueadero')->required()->native(false)->live()
                        ->options(fn () => ParkingLot::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('parking_space_id', null)),

                    Forms\Components\Select::make('vehicle_type_id')
                        ->label('Tipo de vehículo')->required()->native(false)
                        ->options(fn () => VehicleType::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('active', true)
                            ->orderBy('sort_order')->pluck('name', 'id')->all()),

                    Forms\Components\Select::make('parking_space_id')
                        ->label('Espacio (opcional)')->native(false)->searchable()
                        ->placeholder('Sin asignar')
                        ->options(fn (Forms\Get $get) => $get('parking_lot_id')
                            ? ParkingSpace::query()
                                ->where('parking_lot_id', $get('parking_lot_id'))
                                ->where('status', ParkingSpace::STATUS_FREE)
                                ->get()
                                ->sort(function ($a, $b) {
                                    $zoneA = (string) ($a->zone ?? '');
                                    $zoneB = (string) ($b->zone ?? '');
                                    if ($zoneA !== $zoneB) return strcmp($zoneA, $zoneB);
                                    return strnatcasecmp((string) $a->code, (string) $b->code);
                                })
                                ->mapWithKeys(fn (ParkingSpace $s) => [
                                    $s->id => trim(($s->zone ? "[{$s->zone}] " : '').$s->code.($s->is_accessibility ? ' ♿' : '')),
                                ])
                                ->all()
                            : [])
                        ->helperText('Solo se listan espacios libres del parqueadero seleccionado.')
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('plate')
                        ->label('Placa')
                        ->required()
                        ->autofocus()
                        ->maxLength(20)
                        ->placeholder('ABC123')
                        ->extraInputAttributes(['style' => 'text-transform: uppercase; font-size: 18px; font-weight: 700; letter-spacing: 2px;'])
                        ->columnSpan(2),

                    Forms\Components\Textarea::make('notes')
                        ->label('Observaciones (opcional)')
                        ->rows(2)->columnSpan(2),

                    Forms\Components\FileUpload::make('entry_photo_path')
                        ->label('Foto de entrada (opcional)')
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('parking/'.now()->format('Y/m'))
                        ->maxSize(5120)
                        ->helperText('Evidencia de estado al entrar. Recomendado para reclamos.')
                        ->columnSpan(2),
                ]),

            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('checkIn')
                    ->label('Registrar entrada')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->size('xl')
                    ->action('registerEntry'),
            ])->fullWidth(),
        ])->statePath('data');
    }

    public function registerEntry(): void
    {
        try {
            $state = $this->data;
            $session = app(ParkingSessionEngine::class)->checkIn([
                'parking_lot_id' => $state['parking_lot_id'] ?? null,
                'vehicle_type_id' => $state['vehicle_type_id'] ?? null,
                'parking_space_id' => $state['parking_space_id'] ?? null,
                'plate' => $state['plate'] ?? '',
                'notes' => $state['notes'] ?? null,
                'entry_photo_path' => $state['entry_photo_path'] ?? null,
            ]);

            $this->lastSession = $session;

            Notification::make()
                ->title("Entrada registrada · {$session->plate}")
                ->body("Hora: {$session->entry_at->format('d/m/Y H:i:s')}")
                ->success()
                ->send();

            // Reset placa para siguiente vehículo
            $this->data['plate'] = '';
            $this->data['notes'] = null;
            $this->data['entry_photo_path'] = null;
            $this->form->fill($this->data);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('No se pudo registrar la entrada')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }
}
