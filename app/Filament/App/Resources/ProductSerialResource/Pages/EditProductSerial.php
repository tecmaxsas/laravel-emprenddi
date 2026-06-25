<?php

namespace App\Filament\App\Resources\ProductSerialResource\Pages;

use App\Filament\App\Resources\ProductSerialResource;
use App\Models\Location;
use App\Models\ProductSerial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;

/**
 * Edición acotada para corregir errores manuales: cambiar estado, sede o
 * notas. No deja editar serial_number ni product_id (esos son inmutables
 * porque alterarlos rompería la trazabilidad y los unique constraints).
 */
class EditProductSerial extends EditRecord
{
    protected static string $resource = ProductSerialResource::class;

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('serial_number')
                ->label('Número de serie')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\TextInput::make('product.name')
                ->label('Producto')
                ->disabled()
                ->dehydrated(false),

            Forms\Components\Select::make('status')
                ->label('Estado')
                ->options(ProductSerial::STATUSES)
                ->required(),

            Forms\Components\Select::make('location_id')
                ->label('Sede')
                ->options(fn () => Location::query()
                    ->where('company_id', auth()->user()?->company_id)
                    ->where('active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->placeholder('— Sin sede asignada —'),

            Forms\Components\Textarea::make('notes')
                ->label('Notas')
                ->rows(3),
        ]);
    }
}
