<?php

namespace App\Filament\App\Resources\Restaurant\MenuResource\Pages;

use App\Filament\App\Resources\Restaurant\MenuResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMenu extends EditRecord
{
    protected static string $resource = MenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('Ver carta pública')
                ->icon('heroicon-o-eye')
                ->color('success')
                ->url(fn () => route('menu.public', ['slug' => $this->record->slug]))
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }
}
