<?php

namespace App\Filament\App\Resources\ExpenseCategoryResource\Pages;

use App\Filament\App\Resources\ExpenseCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditExpenseCategory extends EditRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    // Sin DeleteAction a proposito: borrar una categoria que ya tiene gastos
    // deja el historico sin clasificar y el reporte de meses pasados cambia
    // solo. Para dejar de usarla esta el interruptor de «Activa».
}
