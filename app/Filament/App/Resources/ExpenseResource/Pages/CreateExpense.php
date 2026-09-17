<?php

namespace App\Filament\App\Resources\ExpenseResource\Pages;

use App\Filament\App\Resources\ExpenseResource;
use App\Models\Company;
use App\Services\Expenses\ExpenseNumberer;
use App\Support\CashSessionGate;
use App\Support\DefaultAccounts;
use App\Support\PaymentAccountResolver;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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

    /**
     * Pone las cuentas contables cuando el formulario no las pidio.
     *
     * Con el modulo de contabilidad apagado la seccion de imputacion no se
     * muestra —no significa nada para una empresa que no lleva libros—, pero el
     * asiento se genera igual y las dos columnas son obligatorias en la base.
     * Asi que la cuenta hay que resolverla por debajo, no dejar de pedirla:
     * ocultar el campo y nada mas deja el gasto imposible de guardar.
     *
     * Tambien cubre el caso raro de que el modulo este encendido y el campo
     * llegue vacio: un gasto sin guardar es peor que un gasto en «Diversos».
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function resolverCuentas(array $data): array
    {
        $companyId = (int) $data['company_id'];

        if (empty($data['expense_account_id'])) {
            $data['expense_account_id'] = DefaultAccounts::gasto($companyId);
        }

        if (empty($data['payment_account_id'])) {
            $data['payment_account_id'] = PaymentAccountResolver::forMethod(
                $data['payment_method'] ?? null,
                $companyId,
            );
        }

        if (! $data['expense_account_id'] || ! $data['payment_account_id']) {
            throw ValidationException::withMessages([
                'expense_account_id' => 'No se pudo determinar la cuenta contable del gasto. '
                    .'Revisa el plan de cuentas de la empresa.',
            ]);
        }

        return $data;
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

        return $this->resolverCuentas($data);
    }
}
