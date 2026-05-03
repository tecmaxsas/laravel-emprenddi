<?php

namespace App\Filament\App\Resources\RoleResource\Pages;

use App\Filament\App\Resources\RoleResource;
use App\Services\Auth\PermissionsCatalog;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (Role $record) => ! in_array($record->name, ['admin'], true)),
        ];
    }

    protected function afterSave(): void
    {
        $selected = collect();
        foreach (PermissionsCatalog::groups() as $group => $perms) {
            $key = "perms_{$group}";
            $values = $this->data[$key] ?? [];
            $selected = $selected->merge($values);
        }

        $this->record->syncPermissions($selected->unique()->values()->all());
    }
}
