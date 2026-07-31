<?php

namespace App\Filament\App\Pages;

use App\Services\ThirdParties\ThirdPartyImportEngine;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

/**
 * Importacion masiva de terceros (clientes y proveedores) desde XLSX.
 * Flujo: descargar plantilla → subir → preview con errores → confirmar.
 */
class ThirdPartyImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Importar terceros';
    protected static ?string $navigationGroup = 'Contabilidad';
    protected static ?int $navigationSort = 15;
    protected static ?string $slug = 'third-parties/import';
    protected static ?string $title = 'Importar terceros desde Excel';

    protected static string $view = 'filament.app.pages.third-party-import';

    public ?array $data = ['file' => null];
    public ?array $preview = null;
    public ?array $result = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('third_parties.manage');
    }

    public function mount(): void
    {
        $this->form->fill(['file' => null]);
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
                        ->directory('tmp/thirdparty-imports')
                        ->visibility('private'),
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

        $absolute = Storage::disk('local')->path($path);
        if (! file_exists($absolute)) {
            Notification::make()->title('El archivo no se encuentra')->danger()->send();
            return;
        }

        try {
            $this->preview = app(ThirdPartyImportEngine::class)->parseAndValidate(
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

        try {
            $this->result = app(ThirdPartyImportEngine::class)->import(
                $this->preview['rows'],
                (int) auth()->user()->company_id,
            );

            $parts = ["Creados: {$this->result['created']}", "Actualizados: {$this->result['updated']}"];
            if (! empty($this->result['errors'])) {
                $parts[] = 'Errores: '.count($this->result['errors']);
            }
            Notification::make()
                ->title('Importación completada')
                ->body(implode(' · ', $parts))
                ->success()->duration(5000)->send();

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
