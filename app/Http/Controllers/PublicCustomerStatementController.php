<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CustomerStatementShare;
use App\Services\Sales\CustomerStatement;
use Illuminate\View\View;

/**
 * El estado de cuenta que el cliente abre desde el WhatsApp que le llegó.
 *
 * Ruta pública, con las mismas dos consecuencias que el catálogo:
 *
 *  1. Sin usuario, `CompanyScope` no filtra —falla abierto—, así que la empresa
 *     se toma del enlace y se pasa a mano a todo lo que se consulta.
 *  2. Cualquiera puede pedirla. El token es aleatorio de 40 caracteres y caduca;
 *     un enlace vencido o inexistente responde 404 sin decir cuál de las dos
 *     cosas es.
 *
 * La hoja se arma en vivo: si el cliente abona hoy y mira el enlace mañana, ve
 * el saldo nuevo.
 */
class PublicCustomerStatementController extends Controller
{
    public function __construct(private readonly CustomerStatement $statement) {}

    public function show(string $token): View
    {
        $share = CustomerStatementShare::query()
            ->withoutGlobalScopes()
            ->where('token', $token)
            ->with(['customer' => fn ($q) => $q->withoutGlobalScopes()])
            ->first();

        abort_if(! $share || ! $share->vigente() || ! $share->customer, 404);

        $share->registrarVisita();

        $datos = $this->statement->build(
            $share->customer,
            $share->from_date?->toDateString(),
            $share->to_date?->toDateString(),
        );

        return view('public.customer-statement', [
            ...$datos,
            'company' => Company::withoutGlobalScopes()->find($share->company_id),
            'share' => $share,
        ]);
    }
}
