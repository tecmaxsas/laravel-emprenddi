<?php

namespace App\Filament\App\Resources\GiftCardResource\Pages;

use App\Filament\App\Resources\GiftCardResource;
use Filament\Resources\Pages\EditRecord;

class EditGiftCard extends EditRecord
{
    protected static string $resource = GiftCardResource::class;

    /**
     * Editar solo permite cambiar campos meta (notas, datos del destinatario).
     * Saldo NO se edita aqui — se ajusta desde la accion 'Ajustar saldo'
     * que pasa por el ledger.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Quitar campos de saldo/estado/codigo del payload por defensa
        unset($data['initial_balance'], $data['current_balance'], $data['status'], $data['code']);
        return $data;
    }
}
