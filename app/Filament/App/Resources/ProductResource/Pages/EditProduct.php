<?php

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Filament\App\Resources\ProductResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Models\Location;
use App\Models\Product;
use App\Services\Inventory\InventoryAdjustmentEngine;
use App\Services\Inventory\InventoryAdjustmentNumberer;
use App\Services\Inventory\InventoryEngine;
use App\Support\DefaultAccounts;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->ajusteDeInventarioAction(),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Ajustar la existencia sin salir del producto.
     *
     * Antes había que irse a Inventario → Ajustes, crear el documento, buscar
     * el producto otra vez y volver. Para cuadrar un conteo —que es de a un
     * producto y muchas veces seguidas— ese viaje es casi todo el trabajo, y
     * lo que pasa cuando cuadrar cuesta es que no se cuadra: el inventario del
     * sistema deja de parecerse al del estante y nadie vuelve a confiar en él.
     *
     * No es un atajo que salte controles: crea el mismo `InventoryAdjustment`
     * con su consecutivo, su motivo y su asiento, y lo contabiliza con el
     * mismo motor. Lo único que cambia es desde dónde se hace.
     */
    protected function ajusteDeInventarioAction(): Actions\Action
    {
        return Actions\Action::make('ajustarInventario')
            ->label('Ajustar inventario')
            ->icon('heroicon-o-scale')
            ->color('warning')
            ->visible(fn () => $this->record->track_inventory
                && auth()->user()?->can('inventory.adjust'))
            ->modalHeading(fn () => 'Ajustar existencia — '.$this->record->name)
            ->modalDescription('Queda registrado como un ajuste de inventario con su motivo y su asiento, igual que si lo hicieras desde Inventario → Ajustes.')
            ->modalSubmitActionLabel('Registrar ajuste')
            ->fillForm(fn () => [
                'date' => now()->toDateString(),
                'direction' => 'in',
                'reason_code' => 'count',
                'location_id' => $this->sedePorDefecto(),
            ])
            ->form([
                Forms\Components\Select::make('location_id')
                    ->label('Sede / bodega')
                    ->options(fn () => Location::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->where('active', true)
                        ->orderByDesc('is_main')->orderBy('name')
                        ->pluck('name', 'id'))
                    ->required()
                    ->native(false)
                    ->live()
                    // Saber cuánto hay antes de escribir evita el ajuste al
                    // revés, que es el error típico: se digita la existencia
                    // que debería quedar en vez de la diferencia.
                    ->helperText(fn (Forms\Get $get) => $this->textoDeExistencia((int) $get('location_id'))),

                Forms\Components\Select::make('direction')
                    ->label('Tipo')
                    ->options(InventoryAdjustment::DIRECTIONS)
                    ->required()
                    ->native(false),

                Forms\Components\TextInput::make('quantity')
                    ->label('Cantidad a ajustar')
                    ->numeric()
                    ->minValue(0.0001)
                    ->required()
                    ->helperText('La DIFERENCIA, no la existencia final.'),

                Forms\Components\Select::make('reason_code')
                    ->label('Motivo')
                    ->options(InventoryAdjustment::REASONS)
                    ->required()
                    ->native(false),

                Forms\Components\TextInput::make('reason_description')
                    ->label('Detalle')
                    ->maxLength(250)
                    ->helperText('Lo que va a explicar este ajuste cuando alguien lo revise dentro de tres meses.'),
            ])
            ->action(function (array $data) {
                try {
                    $ajuste = $this->registrarAjuste($data);

                    Notification::make()
                        ->success()
                        ->title('Ajuste registrado — '.$ajuste->fullNumber())
                        ->body($this->textoDeExistencia((int) $data['location_id']))
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo registrar el ajuste')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    /** @param  array<string, mixed>  $data */
    protected function registrarAjuste(array $data): InventoryAdjustment
    {
        /** @var Product $producto */
        $producto = $this->record;
        $empresa = auth()->user()->company;

        $ajuste = DB::transaction(function () use ($data, $producto, $empresa) {
            $ajuste = InventoryAdjustment::create([
                'company_id' => $producto->company_id,
                'location_id' => $data['location_id'],
                'prefix' => 'AJ',
                'number' => app(InventoryAdjustmentNumberer::class)->next($empresa, 'AJ'),
                'date' => $data['date'] ?? now()->toDateString(),
                'direction' => $data['direction'],
                'reason_code' => $data['reason_code'],
                'reason_description' => $data['reason_description'] ?? null,
                // La contrapartida la decide el sentido: una entrada es una
                // recuperación y una salida es una pérdida. No son la misma
                // cuenta ni el mismo signo.
                'counterpart_account_id' => DefaultAccounts::contrapartidaDeAjuste(
                    $data['direction'],
                    (int) $producto->company_id,
                ),
                'status' => InventoryAdjustment::STATUS_DRAFT,
                'created_by_user_id' => auth()->id(),
            ]);

            InventoryAdjustmentLine::create([
                'inventory_adjustment_id' => $ajuste->id,
                'line_number' => 1,
                'product_id' => $producto->id,
                'quantity' => (float) $data['quantity'],
                'unit_cost' => (float) ($producto->default_purchase_price ?? 0),
            ]);

            return $ajuste;
        });

        return app(InventoryAdjustmentEngine::class)->post($ajuste->fresh(['lines.product']));
    }

    protected function sedePorDefecto(): ?int
    {
        return Location::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('active', true)
            ->orderByDesc('is_main')->orderBy('id')
            ->value('id');
    }

    protected function textoDeExistencia(?int $locationId): string
    {
        if (! $locationId) {
            return 'Elige la sede para ver la existencia actual.';
        }

        // El stock no es una columna: sale del saldo que dejo el ultimo
        // movimiento de inventario. Preguntarselo al motor es lo que impide
        // que esta pantalla muestre una existencia distinta a la del resto
        // del sistema.
        $cantidad = app(InventoryEngine::class)->currentStock(
            (int) $this->record->id,
            $locationId,
        );

        return 'Existencia actual en esta sede: '.rtrim(rtrim(number_format($cantidad, 2, ',', '.'), '0'), ',');
    }
}
