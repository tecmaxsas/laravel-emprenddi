<?php

namespace App\Filament\App\Pages\Parking;

use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingSpace;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Dashboard visual de ocupacion por parqueadero, agrupado por zona.
 * Cada espacio es un tile coloreado por estado:
 *   verde  libre
 *   rojo   ocupado
 *   ambar  reservado
 *   gris   mantenimiento
 *
 * Refresca al cambiar el filtro o pulsando el boton.
 */
class ParkingOccupancy extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';
    protected static ?string $navigationLabel = 'Ocupación';
    protected static ?string $navigationGroup = 'Parqueadero';
    protected static ?int $navigationSort = 5;
    protected static ?string $title = 'Mapa de ocupación';

    protected static string $view = 'filament.app.pages.parking.occupancy';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('parking')) return false;
        return (bool) auth()->user()?->can('parking.use');
    }

    public function mount(): void
    {
        $this->data = [
            'parking_lot_id' => ParkingLot::query()->where('active', true)->orderBy('id')->value('id'),
        ];
        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtro')
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    Forms\Components\Select::make('parking_lot_id')
                        ->label('Parqueadero')->required()->native(false)->live()
                        ->options(fn () => ParkingLot::query()->where('active', true)
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->columnSpan(2),
                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('refresh')
                            ->label('Actualizar')
                            ->icon('heroicon-o-arrow-path')
                            ->action(fn () => null), // re-render via Livewire
                    ]),
                ]),
        ])->statePath('data');
    }

    public function getViewData(): array
    {
        $lotId = (int) ($this->data['parking_lot_id'] ?? 0);
        if (! $lotId) {
            return ['lot' => null, 'zones' => [], 'summary' => null];
        }

        $lot = ParkingLot::find($lotId);
        $spaces = ParkingSpace::query()
            ->where('parking_lot_id', $lotId)
            ->with(['vehicleType:id,name,code', 'activeSession:id,parking_space_id,plate,entry_at'])
            ->orderBy('zone')
            ->orderBy('code')
            ->get();

        $zones = $spaces->groupBy(fn ($s) => $s->zone ?: 'Sin zona');

        $summary = [
            'total' => $spaces->count(),
            'free' => $spaces->where('status', ParkingSpace::STATUS_FREE)->count(),
            'occupied' => $spaces->where('status', ParkingSpace::STATUS_OCCUPIED)->count(),
            'reserved' => $spaces->where('status', ParkingSpace::STATUS_RESERVED)->count(),
            'maintenance' => $spaces->where('status', ParkingSpace::STATUS_MAINTENANCE)->count(),
            'capacity' => $lot?->total_capacity,
        ];

        return [
            'lot' => $lot,
            'zones' => $zones,
            'summary' => $summary,
            'statusColors' => ParkingSpace::STATUS_COLORS,
        ];
    }
}
