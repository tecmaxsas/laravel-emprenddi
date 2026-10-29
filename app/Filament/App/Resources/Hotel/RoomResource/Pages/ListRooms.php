<?php

namespace App\Filament\App\Resources\Hotel\RoomResource\Pages;

use App\Filament\App\Resources\Hotel\RoomResource;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\HotelZone;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use App\Services\Hotel\HotelRoomBulkCreator;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListRooms extends ListRecords
{
    protected static string $resource = RoomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            Actions\Action::make('crearEnLote')
                ->label('Crear en lote')
                ->icon('heroicon-o-squares-plus')
                ->color('gray')
                ->modalHeading('Crear habitaciones en lote')
                ->modalDescription('La numeración de un hotel casi siempre es un rango por piso. '
                    .'Las que ya existan se omiten, así que se puede usar para ampliar un piso ya creado.')
                ->modalSubmitActionLabel('Crear habitaciones')
                ->modalWidth('2xl')
                ->form(static::formularioDelLote())
                ->action(function (array $datos) {
                    try {
                        $resultado = app(HotelRoomBulkCreator::class)->create($datos);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->title('Rango inválido')->body($e->getMessage())->danger()->send();

                        return;
                    } catch (\Throwable $e) {
                        Notification::make()->title('No se pudo crear el lote')
                            ->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $detalle = [];

                    if ($resultado['reactivadas'] > 0) {
                        $detalle[] = $resultado['reactivadas'].' que estaban en papelera se reactivaron.';
                    }

                    if ($resultado['omitidas'] !== []) {
                        $muestra = implode(', ', array_slice($resultado['omitidas'], 0, 10));
                        $detalle[] = count($resultado['omitidas']).' se omitieron porque ya existían: '
                            .$muestra.(count($resultado['omitidas']) > 10 ? '…' : '');
                    }

                    Notification::make()
                        ->title($resultado['creadas'] > 0
                            ? 'Se crearon '.$resultado['creadas'].' habitación(es)'
                            : 'No se creó ninguna habitación nueva')
                        ->body(implode(' ', $detalle) ?: 'Rango generado completo.')
                        ->status($resultado['creadas'] > 0 || $resultado['reactivadas'] > 0 ? 'success' : 'warning')
                        ->send();
                }),
        ];
    }

    /** @return array<int, \Filament\Forms\Components\Component> */
    protected static function formularioDelLote(): array
    {
        $empresa = fn () => auth()->user()?->company_id;

        return [
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('hotel_id')
                    ->label('Hotel')
                    ->required()
                    ->native(false)
                    ->live()
                    ->options(fn () => Hotel::query()
                        ->where('company_id', $empresa())
                        ->where('active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),

                Forms\Components\Select::make('room_type_id')
                    ->label('Tipo de habitación')
                    ->required()
                    ->native(false)
                    ->options(fn (Get $get) => RoomType::query()
                        ->where('company_id', $empresa())
                        ->when($get('hotel_id'), fn ($q) => $q->where('hotel_id', $get('hotel_id')))
                        ->where('active', true)
                        ->orderBy('sort_order')
                        ->pluck('name', 'id')
                        ->all()),

                Forms\Components\Select::make('zone_id')
                    ->label('Zona')
                    ->native(false)
                    ->placeholder('Sin zona')
                    ->options(fn (Get $get) => HotelZone::query()
                        ->where('company_id', $empresa())
                        ->when($get('hotel_id'), fn ($q) => $q->where('hotel_id', $get('hotel_id')))
                        ->where('active', true)
                        ->orderBy('display_order')
                        ->pluck('name', 'id')
                        ->all()),

                Forms\Components\TextInput::make('floor')
                    ->label('Piso')
                    ->maxLength(30)
                    ->placeholder('1'),
            ]),

            Forms\Components\Section::make('Rango de números')
                ->schema([
                    Forms\Components\Grid::make(4)->schema([
                        Forms\Components\TextInput::make('prefix')
                            ->label('Prefijo')
                            ->live(onBlur: true)
                            ->maxLength(10)
                            ->placeholder('opcional'),

                        Forms\Components\TextInput::make('from')
                            ->label('Desde')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(101)
                            ->live(onBlur: true),

                        Forms\Components\TextInput::make('to')
                            ->label('Hasta')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->default(112)
                            ->live(onBlur: true),

                        Forms\Components\TextInput::make('step')
                            ->label('Salto')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->live(onBlur: true),
                    ]),

                    Forms\Components\Select::make('padding')
                        ->label('Relleno con ceros')
                        ->native(false)
                        ->live()
                        ->options([
                            0 => 'Sin relleno (101, 102)',
                            3 => '3 dígitos (001, 002)',
                            4 => '4 dígitos (0001, 0002)',
                        ])
                        ->default(0)
                        ->selectablePlaceholder(false),

                    Forms\Components\Placeholder::make('vistaPrevia')
                        ->label('Vista previa')
                        ->content(fn (Get $get) => static::vistaPrevia($get)),
                ]),

            Forms\Components\Select::make('status')
                ->label('Estado inicial')
                ->native(false)
                ->options(Room::STATUSES)
                ->default(Room::STATUS_AVAILABLE),
        ];
    }

    protected static function vistaPrevia(Get $get): HtmlString
    {
        try {
            $numeros = HotelRoomBulkCreator::construirNumeros([
                'prefix' => $get('prefix'),
                'from' => $get('from'),
                'to' => $get('to'),
                'step' => $get('step'),
                'padding' => $get('padding'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return new HtmlString('<span class="text-danger-600 dark:text-danger-400">'.e($e->getMessage()).'</span>');
        }

        if ($numeros === []) {
            return new HtmlString('<span class="text-gray-500">Define el rango para ver la vista previa.</span>');
        }

        $muestra = count($numeros) <= 8
            ? implode(', ', $numeros)
            : implode(', ', array_slice($numeros, 0, 5)).', … , '.implode(', ', array_slice($numeros, -2));

        return new HtmlString(
            '<span class="font-medium">'.count($numeros).' habitación(es):</span> '
            .'<span class="font-mono">'.e($muestra).'</span>'
        );
    }
}
