<?php

namespace App\Support;

use App\Models\ThirdParty;
use App\Services\Sales\QuickCustomer;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * Formulario de alta rapida de cliente para los selects de Filament.
 *
 * Sirve al `createOptionForm` de cualquier Select de cliente, de modo que se
 * pueda crear uno sin salir de la pantalla en la que se esta. Las reglas —que
 * campos son obligatorios, el digito de verificacion, que hacer con un
 * documento repetido— viven en QuickCustomer, el mismo servicio que usan los
 * POS de retail y restaurante: si cada pantalla las repitiera, terminarian
 * comportandose distinto.
 */
class QuickCustomerForm
{
    /** @return list<Forms\Components\Component> */
    public static function schema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label('Nombre / razón social')
                ->required()
                ->maxLength(200)
                ->columnSpanFull(),

            Forms\Components\Select::make('document_type')
                ->label('Tipo de documento')
                ->options(ThirdParty::DOCUMENT_TYPES)
                ->default('cc')
                ->native(false)
                ->required(),

            Forms\Components\TextInput::make('document_number')
                ->label('Número de documento')
                ->required()
                ->maxLength(30),

            Forms\Components\TextInput::make('email')
                ->label('Correo')
                ->email()
                ->required()
                ->helperText('A esta dirección se le envía la factura electrónica.')
                ->columnSpanFull(),

            Forms\Components\TextInput::make('phone')
                ->label('Teléfono')
                ->maxLength(40),

            Forms\Components\TextInput::make('address')
                ->label('Dirección')
                ->maxLength(255),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return int|null id del cliente, o null si no se pudo crear.
     */
    public static function create(array $data): ?int
    {
        try {
            $resultado = app(QuickCustomer::class)->create(
                (int) auth()->user()?->company_id,
                $data,
            );
        } catch (\RuntimeException $e) {
            Notification::make()->danger()
                ->title('No se pudo crear el cliente')
                ->body($e->getMessage())
                ->send();

            return null;
        }

        if ($resultado['existed']) {
            Notification::make()->warning()
                ->title('Ese documento ya estaba registrado')
                ->body('Se seleccionó '.$resultado['customer']->name.'.')
                ->send();
        }

        return $resultado['customer']->id;
    }
}
