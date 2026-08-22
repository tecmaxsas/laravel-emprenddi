<?php

namespace App\Filament\App\Resources\Parking\ParkingSpaceResource\Pages;

use App\Filament\App\Resources\Parking\ParkingSpaceResource;
use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingSpace;
use App\Models\Parking\VehicleType;
use App\Services\Parking\ParkingSpaceBulkCreator;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListParkingSpaces extends ListRecords
{
    protected static string $resource = ParkingSpaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            Actions\Action::make('bulkCreate')
                ->label('Crear en lote')
                ->icon('heroicon-o-squares-plus')
                ->color('gray')
                ->modalHeading('Crear espacios en lote')
                ->modalDescription('Genera todos los espacios de un rango de una sola vez. Los códigos que ya existan en ese parqueadero se omiten.')
                ->modalSubmitActionLabel('Crear espacios')
                ->modalWidth('3xl')
                ->form(static::bulkCreateForm())
                ->action(function (array $data) {
                    try {
                        $result = app(ParkingSpaceBulkCreator::class)->create(static::normalizeRange($data));
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('Rango inválido')->body($e->getMessage())->danger()->send();

                        return;
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $lines = [];
                    if ($result['restored'] > 0) {
                        $lines[] = $result['restored'].' espacio(s) que estaban en papelera se reactivaron.';
                    }
                    if ($skipped = $result['skipped']) {
                        $muestra = implode(', ', array_slice($skipped, 0, 10));
                        $lines[] = count($skipped).' se omitieron porque ya existían: '.$muestra
                            .(count($skipped) > 10 ? '…' : '');
                    }

                    Notification::make()
                        ->title($result['created'] > 0
                            ? "Se crearon {$result['created']} espacio(s)"
                            : 'No se creó ningún espacio nuevo')
                        ->body(implode(' ', $lines) ?: 'Rango generado completo.')
                        ->status($result['created'] > 0 || $result['restored'] > 0 ? 'success' : 'warning')
                        ->send();
                }),
        ];
    }

    /** @return array<int, Component> */
    protected static function bulkCreateForm(): array
    {
        return [
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('parking_lot_id')
                    ->label('Parqueadero')->required()->native(false)->searchable()
                    ->options(fn () => ParkingLot::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->where('active', true)
                        ->orderBy('name')->pluck('name', 'id')->all()),

                Forms\Components\Select::make('vehicle_type_id')
                    ->label('Tipo de vehículo')->native(false)->searchable()
                    ->placeholder('Cualquier tipo')
                    ->options(fn () => VehicleType::query()
                        ->where('company_id', auth()->user()?->company_id)
                        ->where('active', true)
                        ->orderBy('sort_order')->pluck('name', 'id')->all()),
            ]),

            Forms\Components\Section::make('Rango de códigos')
                ->description('El código se arma como prefijo + secuencia + sufijo.')
                ->schema([
                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\Select::make('mode')
                            ->label('Secuencia')->native(false)->live()
                            ->options(ParkingSpaceBulkCreator::MODES)
                            ->default(ParkingSpaceBulkCreator::MODE_NUMERIC)
                            ->selectablePlaceholder(false),

                        Forms\Components\TextInput::make('prefix')
                            ->label('Prefijo')->live(onBlur: true)
                            ->maxLength(20)->placeholder('A-'),

                        Forms\Components\TextInput::make('suffix')
                            ->label('Sufijo')->live(onBlur: true)
                            ->maxLength(20)->placeholder('opcional'),
                    ]),

                    Forms\Components\Grid::make(3)->schema([
                        Forms\Components\TextInput::make('from_number')
                            ->label('Desde')->required()->live(onBlur: true)
                            ->numeric()->minValue(0)->default(1)
                            ->visible(fn (Get $get) => $get('mode') !== ParkingSpaceBulkCreator::MODE_ALPHA),

                        Forms\Components\TextInput::make('to_number')
                            ->label('Hasta')->required()->live(onBlur: true)
                            ->numeric()->minValue(0)->default(30)
                            ->visible(fn (Get $get) => $get('mode') !== ParkingSpaceBulkCreator::MODE_ALPHA),

                        Forms\Components\TextInput::make('from_letter')
                            ->label('Desde')->required()->live(onBlur: true)
                            ->maxLength(3)->placeholder('A')->default('A')
                            ->visible(fn (Get $get) => $get('mode') === ParkingSpaceBulkCreator::MODE_ALPHA),

                        Forms\Components\TextInput::make('to_letter')
                            ->label('Hasta')->required()->live(onBlur: true)
                            ->maxLength(3)->placeholder('H')->default('H')
                            ->visible(fn (Get $get) => $get('mode') === ParkingSpaceBulkCreator::MODE_ALPHA),

                        Forms\Components\Select::make('padding')
                            ->label('Relleno con ceros')->native(false)->live()
                            ->options([
                                0 => 'Sin relleno (1, 2, 10)',
                                2 => '2 dígitos (01, 02, 10)',
                                3 => '3 dígitos (001, 002, 010)',
                                4 => '4 dígitos (0001, 0002)',
                            ])
                            ->default(2)
                            ->selectablePlaceholder(false)
                            ->visible(fn (Get $get) => $get('mode') !== ParkingSpaceBulkCreator::MODE_ALPHA),

                        Forms\Components\TextInput::make('step')
                            ->label('Salto')->numeric()->minValue(1)->default(1)->live(onBlur: true)
                            ->helperText('1 = consecutivos. 2 = de dos en dos (pares/impares).'),
                    ]),

                    Forms\Components\Placeholder::make('preview')
                        ->label('Vista previa')
                        ->content(fn (Get $get) => static::previewFor($get)),
                ]),

            Forms\Components\Section::make('Datos comunes del lote')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('zone')->label('Zona')
                            ->maxLength(50)->placeholder('Piso 1, Sótano, Norte'),

                        Forms\Components\Select::make('status')->label('Estado')->required()->native(false)
                            ->options(ParkingSpace::STATUSES)->default(ParkingSpace::STATUS_FREE),
                    ]),

                    Forms\Components\Toggle::make('is_accessibility')
                        ->label('Todos accesibles (discapacidad)')
                        ->helperText('Úsalo solo si TODO el rango es de espacios prioritarios.'),

                    Forms\Components\Textarea::make('notes')->label('Notas')->rows(2),
                ])->collapsed(),
        ];
    }

    /**
     * El rango se captura en campos distintos segun el modo (para no montar
     * dos inputs sobre la misma clave de estado); aqui se unifica a from/to,
     * que es lo que entiende el servicio.
     */
    protected static function normalizeRange(array $data): array
    {
        $alpha = ($data['mode'] ?? null) === ParkingSpaceBulkCreator::MODE_ALPHA;

        $data['from'] = $alpha ? ($data['from_letter'] ?? '') : ($data['from_number'] ?? null);
        $data['to'] = $alpha ? ($data['to_letter'] ?? '') : ($data['to_number'] ?? null);

        return $data;
    }

    protected static function previewFor(Get $get): HtmlString
    {
        try {
            $codes = ParkingSpaceBulkCreator::buildCodes(static::normalizeRange([
                'mode' => $get('mode'),
                'prefix' => $get('prefix'),
                'suffix' => $get('suffix'),
                'from_number' => $get('from_number'),
                'to_number' => $get('to_number'),
                'from_letter' => $get('from_letter'),
                'to_letter' => $get('to_letter'),
                'padding' => $get('padding'),
                'step' => $get('step'),
            ]));
        } catch (\InvalidArgumentException $e) {
            return new HtmlString('<span class="text-danger-600 dark:text-danger-400">'.e($e->getMessage()).'</span>');
        }

        if ($codes === []) {
            return new HtmlString('<span class="text-gray-500">Define el rango para ver la vista previa.</span>');
        }

        $muestra = count($codes) <= 8
            ? implode(', ', $codes)
            : implode(', ', array_slice($codes, 0, 5)).', … , '.implode(', ', array_slice($codes, -2));

        return new HtmlString(
            '<span class="font-medium">'.count($codes).' espacio(s):</span> '
            .'<span class="font-mono">'.e($muestra).'</span>'
        );
    }
}
