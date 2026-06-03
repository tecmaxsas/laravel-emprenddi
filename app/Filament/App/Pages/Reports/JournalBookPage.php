<?php

namespace App\Filament\App\Pages\Reports;

use App\Models\JournalEntry;
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

class JournalBookPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms, InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Libro Diario';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?string $title = 'Libro Diario';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.app.pages.reports.report-page';

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) return false;
        return (bool) auth()->user()?->can('reports.journal_book');
    }

    public ?array $filters = [];

    public function mount(): void
    {
        $this->filters = [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'type' => null,
        ];
        $this->form->fill($this->filters);
    }

    public function getExportUrl(): ?string
    {
        return route('reports.export.journal_book', array_filter([
            'from' => $this->filters['from'] ?? null,
            'to' => $this->filters['to'] ?? null,
            'type' => $this->filters['type'] ?? null,
        ]));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Filtros')
                    ->columns(4)
                    ->schema([
                        Forms\Components\DatePicker::make('from')
                            ->label('Desde')
                            ->required()
                            ->live(),
                        Forms\Components\DatePicker::make('to')
                            ->label('Hasta')
                            ->required()
                            ->live(),
                        Forms\Components\Select::make('type')
                            ->label('Tipo de asiento')
                            ->options(JournalEntry::TYPES)
                            ->placeholder('Todos')
                            ->live()
                            ->columnSpan(2),
                    ]),
            ])
            ->statePath('filters');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => JournalEntry::query()
                ->where('status', 'posted')
                ->when($this->filters['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '>=', $d))
                ->when($this->filters['to'] ?? null, fn (Builder $q, $d) => $q->whereDate('date', '<=', $d))
                ->when($this->filters['type'] ?? null, fn (Builder $q, $t) => $q->where('type', $t))
                ->with(['thirdParty']))
            ->defaultSort('date')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Fecha')->date('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('full_number')
                    ->label('Asiento')
                    ->state(fn (JournalEntry $record) => $record->fullNumber())
                    ->fontFamily('mono')
                    ->weight('semibold')
                    ->url(fn (JournalEntry $record) => route('filament.app.resources.journal-entries.view', $record)),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state) => JournalEntry::TYPES[$state] ?? $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('reference')->label('Ref.')->placeholder('—'),
                Tables\Columns\TextColumn::make('thirdParty.name')->label('Tercero')->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('description')->label('Concepto')->limit(80)->wrap(),
                Tables\Columns\TextColumn::make('total_debit')->label('Débito')->money('COP')->alignEnd(),
                Tables\Columns\TextColumn::make('total_credit')->label('Crédito')->money('COP')->alignEnd(),
            ])
            ->paginated([25, 50, 100]);
    }
}
