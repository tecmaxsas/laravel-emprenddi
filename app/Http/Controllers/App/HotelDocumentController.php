<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Hotel\Stay;
use App\Support\HotelSettings;
use Illuminate\Support\Facades\Auth;

/**
 * Los dos papeles que un hotel imprime: la cuenta y la tarjeta de registro.
 *
 * Van en un controlador y no en la pantalla de recepción porque se imprimen
 * desde tres sitios —el folio abierto, el histórico de estadías y el cierre
 * del check-out— y porque abrir una ventana nueva es lo único que hace que la
 * impresora salga con el diálogo del navegador sin perder el estado del POS.
 */
class HotelDocumentController extends Controller
{
    /**
     * La cuenta del huésped: el folio con todos sus renglones.
     *
     * Formato térmico de 80 mm, el mismo de la precuenta del restaurante y del
     * tiquete del parqueadero. Si la estadía ya se facturó, el documento dice
     * con qué factura; si no, lleva el aviso de que NO es una factura de
     * venta. Ese aviso no es configurable: una cuenta se imprime en la misma
     * impresora que la factura, se parece y lleva los mismos totales, así que
     * sin él el huésped se va creyendo que ya la tiene.
     */
    public function folio(int $stay)
    {
        $estadia = $this->resolver($stay);

        return view('hotel.folio', [
            'stay' => $estadia,
            'company' => $estadia->company,
            'hotel' => $estadia->hotel,
            'charges' => $estadia->charges,
            'config' => HotelSettings::config($estadia->company),
        ]);
    }

    /**
     * Tarjeta de registro hotelero.
     *
     * Tamaño carta y no térmica: se firma y se archiva. Lleva los datos que
     * pide el registro —documento, nacionalidad, procedencia, destino, motivo
     * del viaje— de todos los que duermen en la habitación, no solo del que
     * paga.
     */
    public function registration(int $stay)
    {
        $estadia = $this->resolver($stay);

        return view('hotel.registration-card', [
            'stay' => $estadia,
            'company' => $estadia->company,
            'hotel' => $estadia->hotel,
            'guests' => $estadia->guests,
            'config' => HotelSettings::config($estadia->company),
        ]);
    }

    /**
     * La estadía, verificando empresa y módulo.
     *
     * El chequeo del módulo va contra la empresa directamente y no por
     * ModuleGate: esta ruta está fuera del panel de Filament y ahí
     * CurrentCompany no lo ha puesto el middleware del panel.
     */
    protected function resolver(int $stay): Stay
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()->can('hotel.use'), 403);

        $estadia = Stay::with([
            'hotel',
            'room:id,number,name',
            'roomType:id,name',
            'primaryGuest',
            'guests',
            'customer:id,name,document_type,document_number,address,phone',
            'charges',
            'invoice:id,prefix,number,invoice_kind,cufe',
            'company:id,name,nit,dv,document_type,address,phone,city,active_modules',
        ])->find($stay);

        abort_unless($estadia && $estadia->company_id === Auth::user()->company_id, 404);
        abort_unless($estadia->company?->hasModule('hotel'), 403);

        return $estadia;
    }
}
