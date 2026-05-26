<?php

namespace App\Filament\App\Resources\GiftCardResource\Pages;

use App\Filament\App\Resources\GiftCardResource;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;

class CreateGiftCard extends CreateRecord
{
    protected static string $resource = GiftCardResource::class;

    /**
     * Al emitir desde admin (no via POS), generamos codigo unico, dejamos
     * current_balance = initial_balance y creamos la transaccion 'issue'.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $companyId = auth()->user()->company_id;

        $data['code'] = GiftCard::generateCode($companyId);
        $data['current_balance'] = $data['initial_balance'];
        $data['status'] = GiftCard::STATUS_ACTIVE;
        $data['issued_at'] = now();
        $data['issued_by_user_id'] = auth()->id();

        return $data;
    }

    /**
     * Despues de crear la gift card, registrar la transaccion 'issue'
     * en el ledger para que el historial sea completo desde el inicio.
     */
    protected function afterCreate(): void
    {
        DB::transaction(function () {
            $this->record->transactions()->create([
                'company_id' => $this->record->company_id,
                'type' => GiftCardTransaction::TYPE_ISSUE,
                'amount' => $this->record->initial_balance,
                'balance_after' => $this->record->current_balance,
                'user_id' => auth()->id(),
                'notes' => 'Emitida desde admin',
            ]);
        });

        Notification::make()
            ->title("Tarjeta {$this->record->code} emitida")
            ->body("Saldo: \$" . number_format((float) $this->record->current_balance, 0, ',', '.'))
            ->success()
            ->persistent()
            ->send();
    }
}
