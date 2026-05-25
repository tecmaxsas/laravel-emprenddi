<?php

namespace App\Filament\SuperAdmin\Resources\CompanyResource\Pages;

use App\Filament\SuperAdmin\Resources\CompanyResource;
use App\Services\Demo\DemoSeeder;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Action principal: crear empresa nueva (heredada de Filament)
            Actions\CreateAction::make(),

            // Action de demo: crea/actualiza empresas demo con catalogo completo.
            // Idempotente — re-ejecutar no duplica datos.
            Actions\Action::make('seedDemo')
                ->label('Cargar empresa demo')
                ->icon('heroicon-o-sparkles')
                ->color('warning')
                ->modalHeading('Cargar empresa demo')
                ->modalDescription('Crea empresas demo con productos, clientes, proveedores y configuracion completa para empezar a probar el sistema. Idempotente: si ya existen se completan sin duplicar.')
                ->modalSubmitActionLabel('Crear')
                ->form([
                    Select::make('kind')
                        ->label('Tipo de demo')
                        ->options([
                            'retail' => '🛒 Comercio / Retail',
                            'restaurant' => '🍽️ Restaurante',
                            'both' => '📦 Ambas',
                        ])
                        ->required()
                        ->default('both')
                        ->helperText('Retail: ~33 productos en 6 categorias + 8 clientes + 3 proveedores. Restaurante: ~30 productos + modificadores + 18 mesas en 3 zonas + 5 clientes.'),
                ])
                ->action(function (array $data) {
                    $seeder = app(DemoSeeder::class);
                    $kind = $data['kind'];
                    $messages = [];

                    if ($kind === 'retail' || $kind === 'both') {
                        $r = $seeder->seedRetail();
                        $messages[] = sprintf('• RETAIL: %s — %s / %s',
                            $r['company']->name, $r['admin_email'], $r['admin_password']
                        );
                    }
                    if ($kind === 'restaurant' || $kind === 'both') {
                        $r = $seeder->seedRestaurant();
                        $messages[] = sprintf('• RESTAURANTE: %s — %s / %s',
                            $r['company']->name, $r['admin_email'], $r['admin_password']
                        );
                    }

                    Notification::make()
                        ->title('Empresas demo listas')
                        ->body(implode("\n", $messages) . "\n\nIngresa a /app/login con esas credenciales.")
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
