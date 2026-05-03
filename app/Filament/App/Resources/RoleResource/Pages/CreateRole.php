<?php

namespace App\Filament\App\Resources\RoleResource\Pages;

use App\Filament\App\Resources\RoleResource;
use App\Services\Auth\PermissionsCatalog;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function afterCreate(): void
    {
        $this->syncPermissionsFromForm();
    }

    protected function syncPermissionsFromForm(): void
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
