<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\ThirdParty;
use App\Services\Reports\AccountsReceivableAging;
use App\Support\AccountantContext;
use App\Support\CurrentCompany;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/**
 * Cartera por edades: un renglón por cliente.
 *
 * «Cartera (CxC)» lista factura por factura, que sirve para revisar un documento
 * puntual. Este responde la otra pregunta, la de cobranza: **a quién hay que
 * llamar y qué tan tarde va**. Un cliente con diez facturas al día no es el
 * mismo problema que uno con una sola de hace cuatro meses, y en el listado
 * plano los dos se ven igual.
 */
class AccountsReceivableAgingPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Cartera por edades';

    protected static ?string $navigationGroup = 'Reportes operativos';

    protected static ?string $title = 'Cartera por edades — por cliente';

    protected static ?int $navigationSort = 41;

    protected static string $view = 'filament.app.pages.reports.accounts-receivable-aging';

    public ?array $filters = [];

    public static function canAccess(): bool
    {
        if (! AccountantContext::ready()) {
            return false;
        }

        return (bool) auth()->user()?->can('reports.accounts_receivable');
    }

    public function mount(): void
    {
        $this->filters = [
            'as_of' => now()->toDateString(),
            'third_party_id' => null,
            'solo_vencida' => false,
        ];

        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(3)
                ->schema([
                    Forms\Components\DatePicker::make('as_of')
                        ->label('Corte a')
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente')
                        ->placeholder('Todos')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_customer', true)
                            ->where(function ($q) use ($search) {
                                $q->where('name', 'ilike', "%{$search}%")
                                    ->orWhere('document_number', 'ilike', "%{$search}%");
                            })
                            ->orderBy('name')
                            ->limit(30)
                            ->get()
                            ->mapWithKeys(fn (ThirdParty $t) => [$t->id => "{$t->document_number} — {$t->name}"])
                            ->all())
                        ->getOptionLabelUsing(fn ($v) => ThirdParty::find($v)?->name)
                        ->live(),

                    Forms\Components\Toggle::make('solo_vencida')
                        ->label('Solo con cartera vencida')
                        ->helperText('Oculta a los clientes que están al día')
                        ->live(),
                ]),
        ])->statePath('filters');
    }

    public function getExportUrl(): string
    {
        return route('reports.export.accounts_receivable_aging', array_filter([
            'as_of' => $this->filters['as_of'] ?? null,
            'third_party_id' => $this->filters['third_party_id'] ?? null,
            'solo_vencida' => ($this->filters['solo_vencida'] ?? false) ? '1' : null,
        ]));
    }

    protected function getViewData(): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;

        if (! $companyId) {
            return ['filas' => collect(), 'totales' => [], 'tramos' => AccountsReceivableAging::TRAMOS];
        }

        $motor = app(AccountsReceivableAging::class);

        $filas = $motor->porTercero(
            companyId: (int) $companyId,
            corte: $this->filters['as_of'] ?? now()->toDateString(),
            thirdPartyId: $this->filters['third_party_id'] ?? null,
        );

        // El filtro se aplica aquí y no en la consulta porque «vencida» es el
        // resultado del cálculo de edades, no una condición que la base pueda
        // responder: depende de la fecha de corte que eligió el usuario.
        if ($this->filters['solo_vencida'] ?? false) {
            $filas = $filas->filter(fn (array $f) => $f['dias_max'] > 0)->values();
        }

        return [
            'filas' => $filas,
            'totales' => $motor->totales($filas),
            'tramos' => AccountsReceivableAging::TRAMOS,
            'corte' => $this->filters['as_of'] ?? now()->toDateString(),
        ];
    }
}
