<?php

namespace App\Filament\App\Resources\Restaurant\TableResource\Pages;

use App\Filament\App\Resources\Restaurant\TableResource;
use App\Models\Location;
use App\Models\Restaurant\ServiceZone;
use App\Models\Restaurant\Table;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

class TableMap extends Page
{
    protected static string $resource = TableResource::class;

    protected static string $view = 'filament.app.resources.restaurant.table-resource.pages.table-map';

    protected static ?string $title = 'Mapa del salón';

    public ?int $locationId = null;
    public ?int $zoneId = null;

    public function mount(): void
    {
        $this->locationId = Location::query()->where('is_main', true)->value('id')
            ?? Location::query()->value('id');
    }

    public function getLocations()
    {
        return Location::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    public function getZones()
    {
        return ServiceZone::query()
            ->where('active', true)
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->orderBy('display_order')
            ->get();
    }

    public function getTables()
    {
        return Table::query()
            ->where('active', true)
            ->when($this->locationId, fn ($q) => $q->where('location_id', $this->locationId))
            ->when($this->zoneId, fn ($q) => $q->where('zone_id', $this->zoneId))
            ->with('zone')
            ->orderBy('code')
            ->get();
    }

    /**
     * Endpoint Livewire para guardar la nueva posición tras drop.
     */
    public function savePosition(int $tableId, int $x, int $y): void
    {
        $table = Table::find($tableId);
        if (! $table) return;

        $table->update([
            'pos_x' => max(0, min(1000, $x)),
            'pos_y' => max(0, min(1000, $y)),
        ]);

        Notification::make()
            ->title('Mesa '.$table->code.' movida')
            ->success()
            ->send();
    }
}
