<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Support\PromotionsSettings;
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
 * Reporte de uso de promociones — cuanto se ha descontado por cada
 * promocion en el rango seleccionado y cuantas veces se uso.
 *
 * Solo visible si el modulo Promociones esta activo.
 */
class PromotionsReport extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Uso de Promociones';
    protected static ?string $navigationGroup = 'Reportes operativos';
    protected static ?string $title = 'Uso de Promociones';
    protected static ?int $navigationSort = 70;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! PromotionsSettings::moduleActive()) return false;
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can('promotions.view');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'type' => null,
            'only_active' => false,
        ];
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->columns(4)
                ->schema([
                    Forms\Components\DatePicker::make('from')->label('Desde')->required()->live(),
                    Forms\Components\DatePicker::make('to')->label('Hasta')->required()->live(),
                    Forms\Components\Select::make('type')
                        ->label('Tipo de promoción')
                        ->placeholder('Todos')
                        ->options([
                            Promotion::TYPE_PERCENTAGE => 'Porcentaje',
                            Promotion::TYPE_FIXED_AMOUNT => 'Monto fijo',
                            Promotion::TYPE_BOGO => 'BOGO',
                            Promotion::TYPE_VOLUME_TIER => 'Volumen',
                            Promotion::TYPE_BUNDLE => 'Combo',
                        ])
                        ->live(),
                    Forms\Components\Toggle::make('only_active')
                        ->label('Solo promociones activas')
                        ->live()
                        ->inline(false),
                ]),
        ])->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $from = $this->filters['from'] ?? now()->startOfMonth()->toDateString();
                $to = $this->filters['to'] ?? now()->endOfMonth()->toDateString();

                // Subquery con metricas de uso en el rango
                $usagesSub = PromotionUsage::query()
                    ->selectRaw('promotion_id, COUNT(*) as range_uses, SUM(discount_applied) as range_discount, MAX(applied_at) as last_used_at')
                    ->whereDate('applied_at', '>=', $from)
                    ->whereDate('applied_at', '<=', $to)
                    ->groupBy('promotion_id');

                return Promotion::query()
                    ->leftJoinSub($usagesSub, 'u', fn ($join) => $join->on('promotions.id', '=', 'u.promotion_id'))
                    ->select(
                        'promotions.*',
                        \DB::raw('COALESCE(u.range_uses, 0) as range_uses'),
                        \DB::raw('COALESCE(u.range_discount, 0) as range_discount'),
                        'u.last_used_at',
                    )
                    ->when($this->filters['type'] ?? null, fn (Builder $q, $v) => $q->where('promotions.type', $v))
                    ->when($this->filters['only_active'] ?? false, fn (Builder $q) => $q->where('promotions.active', true));
            })
            ->defaultSort('range_discount', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Promoción')
                    ->searchable()
                    ->wrap()
                    ->description(fn ($record) => $record->code ? '🎟️ ' . $record->code : null),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Promotion::TYPE_PERCENTAGE => 'Porcentaje',
                        Promotion::TYPE_FIXED_AMOUNT => 'Monto fijo',
                        Promotion::TYPE_BOGO => 'BOGO',
                        Promotion::TYPE_VOLUME_TIER => 'Volumen',
                        Promotion::TYPE_BUNDLE => 'Combo',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        Promotion::TYPE_PERCENTAGE => 'success',
                        Promotion::TYPE_FIXED_AMOUNT => 'info',
                        Promotion::TYPE_BOGO => 'warning',
                        Promotion::TYPE_VOLUME_TIER => 'primary',
                        Promotion::TYPE_BUNDLE => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('active')
                    ->label('Activa')
                    ->boolean(),

                Tables\Columns\TextColumn::make('range_uses')
                    ->label('Usos en período')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('range_discount')
                    ->label('Descuento otorgado')
                    ->money('COP')
                    ->sortable()
                    ->alignEnd()
                    ->weight('semibold')
                    ->color(fn ($state) => (float) $state > 0 ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('usage_count')
                    ->label('Usos totales')
                    ->numeric()
                    ->alignEnd()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Última vez usada')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('valid_to')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->placeholder('Sin expiración')
                    ->color(fn ($record) => $record->valid_to && $record->valid_to->isPast() ? 'danger' : null)
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('summary')
                    ->label(fn () => $this->summaryLabel())
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->disabled(),
            ]);
    }

    protected function summaryLabel(): string
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;
        if (! $from || ! $to) return 'Total —';

        $totalUses = PromotionUsage::query()
            ->whereDate('applied_at', '>=', $from)
            ->whereDate('applied_at', '<=', $to)
            ->count();

        $totalDiscount = (float) PromotionUsage::query()
            ->whereDate('applied_at', '>=', $from)
            ->whereDate('applied_at', '<=', $to)
            ->sum('discount_applied');

        return sprintf('Total: %d usos · $%s en descuentos',
            $totalUses,
            number_format($totalDiscount, 0, ',', '.'),
        );
    }
}
