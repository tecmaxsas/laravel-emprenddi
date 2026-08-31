<?php

namespace App\Filament\App\Pages\OrderTaking;

use App\Filament\Concerns\ResolvesUploadedFile;
use App\Services\OrderTaking\MacDulcesImporter;
use App\Support\ModuleGate;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Importa los 3 XLSX de MAC DULCES desde la UI. El usuario sube los 2
 * archivos maestros (clientes + precios), se guardan en storage/app/tmp
 * y se llama al mismo MacDulcesImporter que usa el comando artisan.
 *
 * La plantilla de pedidos (archivo 1) no se importa — es solo un ejemplo
 * visual del formato de pedido.
 */
class ImportMacDulces extends Page implements HasForms
{
    use InteractsWithForms, ResolvesUploadedFile;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationLabel = 'Importar catálogo';
    protected static ?string $navigationGroup = 'Toma pedidos';
    protected static ?int $navigationSort = 90;
    protected static ?string $slug = 'order-taking/import';
    protected static ?string $title = 'Importar catálogo desde plantillas Excel';

    protected static string $view = 'filament.app.pages.order-taking.import';

    public ?array $data = ['precios' => null, 'clientes' => null];
    public ?array $result = null;

    public static function canAccess(): bool
    {
        if (! ModuleGate::active('order_taking')) return false;
        return (bool) auth()->user()?->can('order_taking.manage');
    }

    public function mount(): void
    {
        $this->form->fill(['precios' => null, 'clientes' => null]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Sube los archivos maestros')
                ->description('Usa las plantillas oficiales. El archivo de "PLANTILLA PEDIDOS" no se importa — es solo el formato del documento. Para corregir precios de un catálogo ya cargado basta con subir el de listas de precios: los productos se buscan por código, así que se actualizan en lugar de duplicarse.')
                ->schema([
                    Forms\Components\FileUpload::make('precios')
                        ->label('LISTAS DE PRECIOS (.xlsx)')
                        ->required()
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                        ])
                        ->maxSize(10240)
                        ->disk('local')
                        ->directory('tmp/order-taking-imports')
                        ->visibility('private'),

                    Forms\Components\FileUpload::make('clientes')
                        ->label('CATALOGO DE CLIENTES (.xlsx) — opcional')
                        ->helperText('Déjalo vacío si solo vas a corregir precios. Subirlo reescribe los datos de los clientes, incluida la lista asignada y las condiciones de pago.')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                        ])
                        ->maxSize(10240)
                        ->disk('local')
                        ->directory('tmp/order-taking-imports')
                        ->visibility('private'),
                ]),
        ])->statePath('data');
    }

    public function confirmImport(): void
    {
        $precios = $this->data['precios'] ?? null;
        $clientes = $this->data['clientes'] ?? null;

        if (! $precios) {
            Notification::make()->title('Sube al menos el archivo de listas de precios')->warning()->send();
            return;
        }

        $preciosAbs = $this->resolveUploadedFile($precios);
        $clientesAbs = $clientes ? $this->resolveUploadedFile($clientes) : null;

        if (! $preciosAbs || ($clientes && ! $clientesAbs)) {
            Notification::make()->title('No se encuentran los archivos subidos')->danger()->send();
            return;
        }

        try {
            $this->result = app(MacDulcesImporter::class)->import(
                (int) Auth::user()->company_id,
                $preciosAbs,
                $clientesAbs,
            );

            Notification::make()
                ->success()
                ->title('Importación completada')
                ->body(sprintf(
                    'Productos: %d nuevos + %d actualizados · Precios: %d procesados, %d con cambio · %s',
                    $this->result['products_created'],
                    $this->result['products_updated'],
                    $this->result['price_items'],
                    $this->result['price_items_changed'],
                    ($this->result['customers_skipped'] ?? false)
                        ? 'Clientes: sin tocar'
                        : sprintf(
                            'Clientes: %d nuevos + %d actualizados',
                            $this->result['customers_created'],
                            $this->result['customers_updated'],
                        ),
                ))->duration(10000)->send();

            // Limpiar el form para que no reimportemos por accidente
            $this->data = ['precios' => null, 'clientes' => null];
            $this->form->fill($this->data);
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Error al importar')
                ->body($e->getMessage())
                ->persistent()->send();
        }
    }
}
