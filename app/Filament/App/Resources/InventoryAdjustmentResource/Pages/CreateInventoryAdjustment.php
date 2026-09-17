<?php

namespace App\Filament\App\Resources\InventoryAdjustmentResource\Pages;

use App\Filament\App\Resources\InventoryAdjustmentResource;
use App\Models\Company;
use App\Services\Inventory\InventoryAdjustmentNumberer;
use App\Support\DefaultAccounts;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateInventoryAdjustment extends CreateRecord
{
    protected static string $resource = InventoryAdjustmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] = Auth::user()->company_id;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';

        $company = Company::find($data['company_id']);
        $prefix = $data['prefix'] ?? 'AJ';
        $data['number'] = app(InventoryAdjustmentNumberer::class)->next($company, $prefix);

        // Con contabilidad apagada el formulario no pide la contrapartida, pero
        // el asiento se genera igual y la columna es obligatoria en la base.
        // Ocultar el campo y nada mas deja el ajuste imposible de guardar.
        //
        // Una entrada es una recuperacion y una salida es una perdida: no es la
        // misma cuenta ni el mismo signo, y ponerlas al reves deja el estado de
        // resultados al contrario.
        if (empty($data['counterpart_account_id'])) {
            $data['counterpart_account_id'] = DefaultAccounts::contrapartidaDeAjuste(
                $data['direction'] ?? 'out',
                (int) $data['company_id'],
            );

            if (! $data['counterpart_account_id']) {
                throw ValidationException::withMessages([
                    'counterpart_account_id' => 'No se pudo determinar la cuenta contraparte. '
                        .'Revisa el plan de cuentas de la empresa.',
                ]);
            }
        }

        $lineNum = 1;
        $data['lines'] = collect($data['lines'] ?? [])->map(function ($line) use (&$lineNum) {
            $line['line_number'] = $lineNum++;

            return $line;
        })->all();

        return $data;
    }
}
