<?php

namespace App\Http\Controllers;

use App\Models\Warranty;
use Illuminate\Http\Request;

/**
 * Render del comprobante de recepción de garantía. La vista es HTML
 * imprimible (similar al ticket POS) para que la sede entregue al cliente
 * una constancia con: producto, serial, problema reportado, plazo, técnico.
 *
 * Auth via middleware del grupo `web` + chequeo manual de empresa y permiso
 * para que un cliente no pueda ver garantías de otra compañía con cambio
 * de ID en la URL.
 */
class WarrantyPrintController extends Controller
{
    public function show(Request $request, Warranty $warranty)
    {
        abort_unless(auth()->check(), 403);
        abort_unless(auth()->user()->company_id === $warranty->company_id, 403);
        abort_unless(auth()->user()->can('warranties.view'), 403);

        $warranty->load(['customer', 'product', 'serial', 'saleInvoice', 'location', 'assignedUser', 'receivedByUser']);
        $company = $warranty->company;

        return view('warranties.print', compact('warranty', 'company'));
    }
}
