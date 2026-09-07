<?php

use App\Models\Company;
use App\Support\CompanyRoles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cada empresa recibe el juego completo de roles.
 *
 * La migracion anterior fue conservadora a proposito: solo clono los roles que
 * los usuarios ya tenian asignados, para que nadie ganara ni perdiera acceso.
 * El efecto secundario es que a una empresa donde todos eran "admin" le quedo
 * ese unico rol, y su administrador no encuentra "cajero" para asignarselo a
 * nadie.
 *
 * Aqui se le completan los que falten, copiados de las plantillas. Es
 * idempotente y no toca los que ya tenga: si a alguno le habian ajustado los
 * permisos, se respeta.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Company::withoutGlobalScopes()->get() as $company) {
            CompanyRoles::provision($company);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // No se revierte: quitar roles que alguien pudo haber asignado ya
        // dejaria usuarios sin acceso sin avisar. Los sobrantes no estorban.
    }
};
