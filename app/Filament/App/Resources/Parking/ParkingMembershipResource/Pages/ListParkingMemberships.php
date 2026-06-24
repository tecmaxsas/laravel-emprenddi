<?php

namespace App\Filament\App\Resources\Parking\ParkingMembershipResource\Pages;

use App\Filament\App\Resources\Parking\ParkingMembershipResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListParkingMemberships extends ListRecords
{
    protected static string $resource = ParkingMembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
