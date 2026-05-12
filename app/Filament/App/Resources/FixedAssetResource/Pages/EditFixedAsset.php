<?php

namespace App\Filament\App\Resources\FixedAssetResource\Pages;

use App\Filament\App\Resources\FixedAssetResource;
use App\Models\FixedAsset;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFixedAsset extends EditRecord
{
    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (FixedAsset $r) => $r->depreciations()->count() === 0 && $r->isActive()),
        ];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Solo activos sin depreciaciones aún se pueden editar libremente.
        // Si ya tiene depreciaciones, redirigir a vista (evita cambiar costo
        // o vida útil después de empezar a depreciar).
        if ($this->record->depreciations()->count() > 0 || $this->record->isDisposed()) {
            $this->redirect(FixedAssetResource::getUrl('view', ['record' => $this->record]));
        }
    }
}
