<?php

namespace App\Filament\App\Pages;

use App\Models\Account;
use App\Models\PayrollAccountMapping;
use App\Services\Payroll\PayrollAccountSlots;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class PayrollAccountSetupPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Cuentas de Nómina';

    protected static ?string $navigationGroup = 'Nómina';

    protected static ?string $title = 'Cuentas contables de la nómina';

    protected static ?int $navigationSort = 40;

    protected static string $view = 'filament.app.pages.payroll-account-setup';

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) {
            return false;
        }

        return (bool) auth()->user()?->can('payroll.accounts.manage');
    }

    public ?array $data = [];

    public function mount(): void
    {
        $existing = PayrollAccountMapping::query()->pluck('account_id', 'slot')->toArray();
        $this->form->fill($existing);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Cuentas de gasto (débito)')
                    ->description('Dónde se registra el costo de la nómina para la empresa.')
                    ->columns(2)
                    ->schema($this->slotFields('expense')),

                Forms\Components\Section::make('Cuentas por pagar (crédito)')
                    ->description('Los pasivos que quedan pendientes de pago al cerrar la nómina.')
                    ->columns(2)
                    ->schema($this->slotFields('payable')),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int,Forms\Components\Select>
     */
    protected function slotFields(string $group): array
    {
        $slots = $group === 'expense'
            ? PayrollAccountSlots::expenseSlots()
            : PayrollAccountSlots::payableSlots();

        $fields = [];
        foreach ($slots as $slot => $info) {
            $fields[] = Forms\Components\Select::make($slot)
                ->label($info['name'])
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => Account::query()
                    ->where('accepts_movements', true)
                    ->where('active', true)
                    ->where(function ($q) use ($search) {
                        $q->where('code', 'like', "%{$search}%")
                          ->orWhere('name', 'ilike', "%{$search}%");
                    })
                    ->orderBy('code')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (Account $a) => [$a->id => "{$a->code} — {$a->name}"])
                    ->all())
                ->getOptionLabelUsing(fn ($value) => Account::find($value)
                    ? Account::find($value)->code.' — '.Account::find($value)->name
                    : null);
        }

        return $fields;
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach (PayrollAccountSlots::codes() as $slot) {
            $accountId = $data[$slot] ?? null;
            if ($accountId) {
                PayrollAccountMapping::updateOrCreate(
                    ['slot' => $slot],
                    ['account_id' => $accountId],
                );
            } else {
                PayrollAccountMapping::query()->where('slot', $slot)->delete();
            }
        }

        Notification::make()
            ->success()
            ->title('Cuentas de nómina guardadas')
            ->send();
    }
}
