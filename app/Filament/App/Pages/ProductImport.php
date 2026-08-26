<?php

namespace App\Filament\App\Pages;

use App\Filament\Concerns\ResolvesUploadedFile;
use App\Services\Products\ProductImportEngine;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Importacion masiva de productos desde XLSX.
 * Flujo:
 *   1. Descargar plantilla
 *   2. Subir archivo
 *   3. Ver preview con errores (por fila)
 *   4. Confirmar → importa dentro de una transaccion
 */
class ProductImport extends Page implements HasForms
{
    use InteractsWithForms, ResolvesUploadedFile;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Importar productos';
    protected static ?string $navigationGroup = 'Productos';
    protected static ?int $navigationSort = 20;
    protected static ?string $slug = 'products/import';
    protected static ?string $title = 'Importar productos desde Excel';

    protected static string $view = 'filament.app.pages.product-import';

    public ?array $data = ['file' => null, 'counterpart_account_id' => null];
    public ?array $preview = null;
    public ?array $result = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('products.manage');
    }

    public function mount(): void
    {
        // Precarga cuenta contrapartida sugerida (3705 Resultados de
        // ejercicios anteriores) si existe.
        $companyId = auth()->user()?->company_id;
        $defaultCounterpart = \App\Models\Account::query()
            ->where('company_id', $companyId)
            ->where('accepts_movements', true)
            ->where('active', true)
            ->where('code', '3705')
            ->value('id');
        $this->form->fill([
            'file' => null,
            'counterpart_account_id' => $defaultCounterpart,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Subir archivo XLSX')
                ->description('Usa la plantilla oficial. Al subir se hace validación línea por línea; nada se guarda hasta que confirmes.')
                ->schema([
                    Forms\Components\FileUpload::make('file')
                        ->label('Archivo (XLSX)')
                        ->required()
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                        ])
                        ->maxSize(10240)
                        ->disk('local')
                        ->directory('tmp/product-imports')
                        ->visibility('private'),

                    Forms\Components\Select::make('counterpart_account_id')
                        ->label('Cuenta contrapartida (CR) — para el asiento del inventario inicial')
                        ->helperText('Solo se usa si la hoja "Inventario Inicial" tiene filas. Sugerido: 3705 Resultados de ejercicios anteriores o 3115 Capital social.')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => \App\Models\Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('accepts_movements', true)
                            ->where('active', true)
                            ->where(function ($q) use ($search) {
                                $q->where('code', 'like', "%{$search}%")
                                    ->orWhere('name', 'ilike', "%{$search}%");
                            })
                            ->orderBy('code')
                            ->limit(30)
                            ->get(['id', 'code', 'name'])
                            ->mapWithKeys(fn ($a) => [$a->id => "{$a->code} — {$a->name}"])
                            ->all())
                        ->getOptionLabelUsing(function ($value) {
                            $a = \App\Models\Account::query()
                                ->where('company_id', auth()->user()?->company_id)
                                ->find($value);
                            return $a ? "{$a->code} — {$a->name}" : null;
                        }),
                ]),
        ])->statePath('data');
    }

    public function analyzeFile(): void
    {
        $path = $this->data['file'] ?? null;
        if (! $path) {
            Notification::make()->title('Sube un archivo primero')->warning()->send();
            return;
        }

        $absolute = $this->resolveUploadedFile($path);
        if (! $absolute) {
            Notification::make()->title('El archivo no se encuentra')->danger()->send();
            return;
        }

        try {
            $this->preview = app(ProductImportEngine::class)->parseAndValidate(
                $absolute,
                (int) auth()->user()->company_id,
            );
            $this->result = null;
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error al leer el archivo')
                ->body($e->getMessage())
                ->danger()->persistent()->send();
        }
    }

    public function confirmImport(): void
    {
        if (! $this->preview || empty($this->preview['valid'])) {
            Notification::make()->title('Hay errores en el preview — corrige antes de confirmar')->warning()->send();
            return;
        }

        $stockRows = $this->preview['stock_rows'] ?? [];
        $counterpartId = null;
        if (! empty($stockRows)) {
            $counterpartId = (int) ($this->data['counterpart_account_id'] ?? 0) ?: null;
            if (! $counterpartId) {
                Notification::make()
                    ->title('Selecciona la cuenta contrapartida')
                    ->body('Hay filas en la hoja "Inventario Inicial" pero no se eligió la cuenta CR para el asiento contable.')
                    ->warning()->send();
                return;
            }
        }

        try {
            $this->result = app(ProductImportEngine::class)->import(
                $this->preview['rows'],
                (int) auth()->user()->company_id,
                $stockRows,
                $counterpartId,
            );

            $parts = ["Creados: {$this->result['created']}", "Actualizados: {$this->result['updated']}"];
            if (! empty($this->result['openings'])) {
                $parts[] = 'Aperturas de inventario: '.count($this->result['openings']);
            }
            if (! empty($this->result['errors'])) {
                $parts[] = 'Errores: '.count($this->result['errors']);
            }
            Notification::make()
                ->title('Importación completada')
                ->body(implode(' · ', $parts))
                ->success()
                ->duration(5000)
                ->send();

            // Limpia el preview para que el usuario suba otro archivo
            $this->preview = null;
            $this->data['file'] = null;
            $this->form->fill($this->data);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error al importar')
                ->body($e->getMessage())
                ->danger()->persistent()->send();
        }
    }

    public function resetPreview(): void
    {
        $this->preview = null;
        $this->result = null;
        $this->data['file'] = null;
        $this->form->fill($this->data);
    }
}
