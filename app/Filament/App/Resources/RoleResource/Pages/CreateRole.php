<?php

namespace App\Filament\App\Resources\RoleResource\Pages;

use App\Filament\App\Resources\RoleResource;
use App\Services\Auth\PermissionsCatalog;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * El rol nace de la empresa que lo crea. Sin esto quedaria como plantilla
     * del sistema y lo verian todas.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = auth()->user()?->company_id;

        return $data;
    }

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
