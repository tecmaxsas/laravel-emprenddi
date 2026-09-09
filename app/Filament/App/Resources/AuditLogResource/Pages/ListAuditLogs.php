<?php

namespace App\Filament\App\Resources\AuditLogResource\Pages;

use App\Filament\App\Resources\AuditLogResource;
use Filament\Resources\Pages\ListRecords;

/**
 * El listado es la pantalla completa: no hay crear, ni editar, ni ver aparte.
 * El detalle de cada entrada se abre en un modal desde la tabla.
 */
class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    public function getSubheading(): ?string
    {
        return 'Cada acción de los usuarios de la empresa: qué hicieron, cuándo y desde dónde. '
            .'Los registros no se pueden modificar ni borrar.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
