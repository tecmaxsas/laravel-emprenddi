<?php

namespace App\Filament\App\Resources\ExpenseResource\Pages;

use App\Filament\App\Resources\ExpenseResource;
use App\Models\Company;
use App\Services\Expenses\ExpenseNumberer;
use App\Support\CashSessionGate;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    /**
     * Bloquea el registro de gastos si el operador no tiene caja abierta.
     * Mismo gate que ventas y compras.
     */
    public function mount(): void
    {
        if (! CashSessionGate::hasOpenSession()) {
            Notification::make()
                ->title('Necesitas una caja abierta')
                ->body('Para registrar un gasto debes abrir primero la caja registradora desde el POS.')
                ->warning()
                ->persistent()
                ->send();

            $this->redirect(ExpenseResource::getUrl('index'));
            return;
        }

        parent::mount();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $session = CashSessionGate::requireOpenSession();

        $data['company_id'] = Auth::user()->company_id;
        $data['cash_register_session_id'] = $session->id;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';

        // Auto-numeración
        $company = Company::find($data['company_id']);
        $prefix = $data['prefix'] ?? 'EXP';
        $data['number'] = app(ExpenseNumberer::class)->next($company, $prefix);

        return $data;
    }
}
