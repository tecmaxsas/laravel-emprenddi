<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Parking\ParkingSession;
use App\Support\ModuleGate;
use Illuminate\Support\Facades\Auth;

class ParkingTicketController extends Controller
{
    /**
     * Renderiza el ticket imprimible de una sesion de parqueadero con un
     * codigo QR cuyo contenido es PK<id>. Al escanearlo en el terminal,
     * abre directamente la modal de salida de esa sesion.
     */
    public function show(int $session)
    {
        abort_unless(ModuleGate::active('parking'), 403);
        abort_unless(Auth::user()?->can('parking.use'), 403);

        $record = ParkingSession::with([
            'parkingLot:id,name,address,phone,city',
            'vehicleType:id,name,icon,code',
            'space:id,code,zone',
            'parkingMembership:id,name',
        ])->find($session);

        abort_unless($record && $record->company_id === Auth::user()->company_id, 404);

        $company = Auth::user()->company;

        return view('parking.ticket', [
            'session' => $record,
            'company' => $company,
            'qr_payload' => 'PK'.$record->id,
            'ticket_number' => '#'.str_pad((string) $record->id, 6, '0', STR_PAD_LEFT),
        ]);
    }
}
