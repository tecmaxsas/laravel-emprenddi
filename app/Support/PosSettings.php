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

    /**
     * Si se le muestra al cliente lo que debe.
     *
     * Util para el negocio que fia: el cliente se entera de su saldo cada vez
     * que compra, sin que nadie tenga que sacarle el tema.
     *
     * Apagado por defecto, y no por prudencia tecnica: en un mostrador con
     * gente detras, imprimir lo que alguien debe lo deja a la vista de quien
     * este mirando. Que se encienda es una decision del negocio, no algo que
     * se le imponga a todos.
     */
    public static function showsCustomerBalance(?int $companyId = null): bool
    {
        return (bool) data_get(self::empresa($companyId)?->settings ?? [], 'pos.show_customer_balance', false);
    }

    /**
     * Si la empresa cobra domicilio desde el POS de retail.
     *
     * Apagado por defecto: a un negocio que no reparte, un boton de domicilio
     * en la pantalla de cobro es un sitio mas donde equivocarse.
     */
    public static function deliveryEnabled(?int $companyId = null): bool
    {
        return (bool) data_get(self::empresa($companyId)?->settings ?? [], 'pos.delivery_enabled', false);
    }

    /**
     * El valor sugerido del domicilio.
     *
     * Sugerido y no fijo: casi todos cobran lo mismo casi siempre, y teclear
     * la misma cifra veinte veces al dia es como se escribe 5000 donde iba
     * 15000. Pero el cajero lo cambia cuando la entrega es lejos, porque
     * obligarlo a un valor unico termina en «se lo sumo a un producto», que
     * descuadra el inventario para cuadrar la plata.
     */
    public static function deliveryFee(?int $companyId = null): float
    {
        return round((float) data_get(self::empresa($companyId)?->settings ?? [], 'pos.delivery_fee', 0), 2);
    }

    private static function empresa(?int $companyId = null): ?Company
    {
        if ($companyId) {
            return Company::withoutGlobalScopes()->find($companyId);
        }

        return app(CurrentCompany::class)->get()
            ?? (auth()->user()?->company_id
                ? Company::withoutGlobalScopes()->find(auth()->user()->company_id)
                : null);
    }
}
