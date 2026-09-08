<?php

namespace App\Support;

use App\Models\Company;

/**
 * Ajustes del POS de retail que otras partes necesitan consultar.
 *
 * "Permitir vender sin stock" se configuraba y no hacia nada: el POS lo leia
 * para su formulario pero llamaba a SaleInvoiceEngine::post() sin pasarlo, y
 * el motor rechazaba la venta igual. El restaurante si lo pasaba, asi que la
 * misma empresa se comportaba distinto segun por donde vendiera.
 *
 * Vive aqui y no en la pagina del POS porque tambien lo necesita la
 * contabilizacion manual de una factura, que no pasa por el POS.
 */
class PosSettings
{
    /**
     * Si la empresa permite que una venta deje el inventario en negativo.
     *
     * Sin esto, la venta se traba cuando el saldo no alcanza. Con esto, sale y
     * el faltante queda visible en el kardex, que es lo que pide un negocio
     * que factura antes de haber registrado la entrada de mercancia.
     */
    public static function allowsNegativeStock(?int $companyId = null): bool
    {
        $company = $companyId
            ? Company::withoutGlobalScopes()->find($companyId)
            : (app(CurrentCompany::class)->get()
                ?? (auth()->user()?->company_id
                    ? Company::withoutGlobalScopes()->find(auth()->user()->company_id)
                    : null));

        return (bool) data_get($company?->settings ?? [], 'pos.allow_negative_stock', false);
    }
}
