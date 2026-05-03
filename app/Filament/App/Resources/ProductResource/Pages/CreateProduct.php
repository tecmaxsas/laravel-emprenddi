<?php

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Filament\App\Resources\ProductResource;
use App\Models\Location;
use App\Services\Inventory\InventoryEngine;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function afterCreate(): void
    {
        $product = $this->record;
        $linesData = $this->data['productLocations'] ?? [];

        $openingEntries = [];

        foreach ($linesData as $line) {
            $qty = (float) ($line['initial_quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $cost = (float) ($line['initial_unit_cost'] ?? 0);
            if ($cost <= 0) {
                $cost = (float) $product->default_purchase_price;
            }

            $location = Location::find($line['location_id'] ?? null);
            if (! $location) {
                continue;
            }

            try {
                app(InventoryEngine::class)->addMovement($product, $location, [
                    'type' => 'opening',
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                    'description' => "Carga inicial al crear el producto {$product->code}",
                ]);

                $openingEntries[] = [
                    'location_id' => $location->id,
                    'quantity' => $qty,
                    'unit_cost' => $cost,
                ];
            } catch (\Throwable $e) {
                Notification::make()
                    ->warning()
                    ->title("Carga inicial fallida en {$location->fullName()}")
                    ->body($e->getMessage())
                    ->send();
            }
        }

        if (! empty($openingEntries)) {
            $journalEntry = app(InventoryEngine::class)->createOpeningJournalEntry($product, $openingEntries);

            if ($journalEntry) {
                Notification::make()
                    ->success()
                    ->title('Carga inicial registrada')
                    ->body("Se generó el asiento contable {$journalEntry->fullNumber()} y los movimientos de inventario.")
                    ->send();
            } else {
                Notification::make()
                    ->warning()
                    ->title('Inventario cargado sin asiento contable')
                    ->body('Los movimientos quedaron registrados pero no se generó asiento (faltan cuentas 1435 o 3705 en el PUC). Crea el asiento de apertura manualmente.')
                    ->send();
            }
        }
    }
}
