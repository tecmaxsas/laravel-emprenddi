<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Support\GiftCardsSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reporte de gift cards — estado del programa de tarjetas regalo.
 *
 * Muestra:
 *  - KPIs: emitidas, redimidas, saldo en circulacion (header)
 *  - Tabla detallada de tarjetas con filtros y drill-down
 *
 * El saldo en circulacion es un PASIVO contable (la empresa le debe
 * mercancia a los tenedores). Util para conciliar contra cuenta 240825
 * 'Anticipos y avances recibidos' u otra similar del PUC.
 */
class GiftCardsReport extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-gift';
    protected static ?string $navigationLabel = 'Estado Gift Cards';
    protected static ?string $navigationGroup = 'Reportes';
    protected static ?string $title = 'Estado Gift Cards';
    protected static ?int $navigationSort = 81;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! GiftCardsSettings::moduleActive()) return false;
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can('gift_cards.view');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'status' => null,
            'expiring_soon' => false,
        ];
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(4)
                ->schema([
                    Forms\Components\DatePicker::make('from')
                        ->label('Emitidas desde')
                        ->required()
                        ->live(),
                    Forms\Components\DatePicker::make('to')
                        ->label('Emitidas hasta')
                        ->required()
                        ->live(),
                    Forms\Components\Select::make('status')
                        ->label('Estado')
                        ->placeholder('Todos')
                        ->options([
                            GiftCard::STATUS_ACTIVE => 'Activa (con saldo)',
                            GiftCard::STATUS_FULLY_REDEEMED => 'Redimida totalmente',
                            GiftCard::STATUS_EXPIRED => 'Expirada',
                            GiftCard::STATUS_CANCELLED => 'Cancelada',
                        ])
                        ->live(),
                    Forms\Components\Toggle::make('expiring_soon')
                        ->label('Solo: vencen en 30 días')
                        ->live()
                        ->inline(false),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $from = $this->filters['from'] ?? now()->subMonths(3)->startOfMonth()->toDateString();
                $to = $this->filters['to'] ?? now()->toDateString();

                return GiftCard::query()
                    ->whereDate('issued_at', '>=', $from)
                    ->whereDate('issued_at', '<=', $to)
                    ->when($this->filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
                    ->when($this->filters['expiring_soon'] ?? false, fn (Builder $q) => $q
                        ->where('status', GiftCard::STATUS_ACTIVE)
                        ->whereBetween('expires_at', [now(), now()->addDays(30)]))
                    ->with(['issuedBy:id,name']);
            })
            ->defaultSort('issued_at', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $s) => match ($s) {
                        GiftCard::STATUS_ACTIVE => 'Activa',
                        GiftCard::STATUS_FULLY_REDEEMED => 'Redimida',
                        GiftCard::STATUS_EXPIRED => 'Expirada',
                        GiftCard::STATUS_CANCELLED => 'Cancelada',
                        default => $s,
                    })
                    ->color(fn (string $s) => match ($s) {
                        GiftCard::STATUS_ACTIVE => 'success',
                        GiftCard::STATUS_FULLY_REDEEMED => 'gray',
                        GiftCard::STATUS_EXPIRED => 'danger',
                        GiftCard::STATUS_CANCELLED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('initial_balance')
                    ->label('Emitida por')
                    ->money('COP')
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('current_balance')
                    ->label('Saldo actual')
                    ->money('COP')
                    ->sortable()
                    ->alignEnd()
                    ->weight('bold')
                    ->color(fn ($state) => (float) $state > 0 ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('redeemed')
                    ->label('Redimido')
                    ->state(fn ($record) => (float) $record->initial_balance - (float) $record->current_balance)
                    ->money('COP')
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Emitida')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->placeholder('Sin expiración')
                    ->color(fn ($record) => $record->expires_at && $record->expires_at->isPast() ? 'danger' : null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_redeemed_at')
                    ->label('Última redención')
                    ->date('d/m/Y')
                    ->placeholder('Sin redenciones')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('issuedBy.name')
                    ->label('Vendida por')
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('recipient_name')
                    ->label('Destinatario')
                    ->placeholder('—')
                    ->toggleable()
                    ->limit(25),
            ])
            ->headerActions([
                Tables\Actions\Action::make('summary')
                    ->label(fn () => $this->summaryLabel())
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->disabled(),
            ]);
    }

    /**
     * Header con KPIs del programa. Muestra:
     *  - Emitidas (total $ con que se cargaron tarjetas en el periodo)
     *  - Redimidas (total $ consumido en POS)
     *  - En circulacion (suma de current_balance de activas — PASIVO)
     *  - Por expirar 30 dias
     */
    protected function summaryLabel(): string
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;
        if (! $from || ! $to) return 'KPIs —';

        // Total emitido en el periodo (todas las que se emitieron entre from y to)
        $issuedSum = (float) GiftCard::query()
            ->whereDate('issued_at', '>=', $from)
            ->whereDate('issued_at', '<=', $to)
            ->sum('initial_balance');

        // Total redimido en el periodo (redenciones a traves de transactions)
        $redeemedSum = abs((float) GiftCardTransaction::query()
            ->where('type', GiftCardTransaction::TYPE_REDEEM)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->sum('amount'));

        // Saldo en circulacion (todas las activas con saldo > 0, no filtra por periodo)
        $circulating = (float) GiftCard::query()
            ->where('status', GiftCard::STATUS_ACTIVE)
            ->where('current_balance', '>', 0)
            ->sum('current_balance');

        return sprintf(
            '📤 Emitidas $%s · 💳 Redimidas $%s · 🔵 En circulación $%s',
            number_format($issuedSum, 0, ',', '.'),
            number_format($redeemedSum, 0, ',', '.'),
            number_format($circulating, 0, ',', '.'),
        );
    }
}
