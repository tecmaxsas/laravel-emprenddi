<?php

namespace App\Filament\App\Pages\Reports;

use App\Services\Exogena\ExogenaCatalog;
use App\Services\Exogena\ExogenaEngine;
use App\Services\Exogena\ExogenaExcelExporter;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class InformacionExogenaPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Información Exógena';

    protected static ?string $navigationGroup = 'Contabilidad';

    protected static ?string $title = 'Información Exógena (Medios Magnéticos)';

    protected static ?int $navigationSort = 80;

    protected static string $view = 'filament.app.pages.reports.informacion-exogena';

    public static function canAccess(): bool
    {
        if (! \App\Support\AccountantContext::ready()) {
            return false;
        }

        return (bool) auth()->user()?->can('exogena.view');
    }

    public ?array $filters = [];

    public bool $generated = false;

    /** Tope de cuantías mínimas para agrupar terceros menores al exportar. */
    public string $exportThreshold = '0';

    public function mount(): void
    {
        $this->filters = [
            'year' => now()->year - 1,
            'format_code' => '1001',
        ];
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Parámetros')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('year')
                            ->label('Año gravable')
                            ->options(collect(range(now()->year, now()->year - 6))
                                ->mapWithKeys(fn ($y) => [$y => (string) $y])
                                ->all())
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(fn () => $this->generated = false),

                        Forms\Components\Select::make('format_code')
                            ->label('Formato')
                            ->options(ExogenaCatalog::formatOptions())
                            ->required()
                            ->native(false)
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn () => $this->generated = false),
                    ]),
            ])
            ->statePath('filters');
    }

    public function generate(): void
    {
        $this->generated = true;
        unset($this->rows);
    }

    /**
     * Filas agregadas del formato seleccionado.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getRowsProperty(): array
    {
        $formatCode = (string) ($this->filters['format_code'] ?? '1001');
        $year = (int) ($this->filters['year'] ?? (now()->year - 1));

        return app(ExogenaEngine::class)->build($formatCode, $year);
    }

    public function getCurrentFormatProperty(): ?array
    {
        return ExogenaCatalog::format((string) ($this->filters['format_code'] ?? '1001'));
    }

    /**
     * Descarga el formato seleccionado como .xlsx para el prevalidador DIAN.
     */
    public function exportExcel(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $formatCode = (string) ($this->filters['format_code'] ?? '1001');
        $year = (int) ($this->filters['year'] ?? (now()->year - 1));
        $threshold = (float) ($this->exportThreshold !== '' ? $this->exportThreshold : 0);

        $result = app(ExogenaExcelExporter::class)->export($formatCode, $year, $threshold);

        return response()->streamDownload(
            fn () => print($result['content']),
            $result['filename'],
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
