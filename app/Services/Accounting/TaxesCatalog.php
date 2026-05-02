<?php

namespace App\Services\Accounting;

/**
 * Catálogo de impuestos colombianos típicos para sembrar en cada empresa.
 *
 * Estructura: cada entrada referencia las cuentas del PUC por CÓDIGO
 * (no por id). El provisioner las busca y resuelve al provisionar.
 *
 * Mínimos de UVT son referenciales 2024-2026; el usuario puede ajustar.
 * (UVT 2026 ≈ $50.500 COP, valores aprox.)
 */
class TaxesCatalog
{
    public static function standard(): array
    {
        return [
            // ===== IVA =====
            [
                'code' => 'IVA-19',
                'name' => 'IVA 19%',
                'type' => 'vat',
                'rate' => 19.0000,
                'sale_account_code' => '240805',     // IVA generado (pasivo)
                'purchase_account_code' => '240810', // IVA descontable (activo/contra-pasivo)
                'minimum_base' => 0,
                'applies_to' => 'both',
                'is_default_for_sale' => true,
                'is_default_for_purchase' => true,
                'description' => 'Tarifa general del IVA en Colombia.',
            ],
            [
                'code' => 'IVA-5',
                'name' => 'IVA 5%',
                'type' => 'vat',
                'rate' => 5.0000,
                'sale_account_code' => '240805',
                'purchase_account_code' => '240810',
                'minimum_base' => 0,
                'applies_to' => 'both',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Tarifa diferencial del IVA (productos con tarifa reducida).',
            ],
            [
                'code' => 'IVA-0',
                'name' => 'IVA 0% (Exento)',
                'type' => 'vat',
                'rate' => 0.0000,
                'sale_account_code' => '240805',
                'purchase_account_code' => '240810',
                'minimum_base' => 0,
                'applies_to' => 'both',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Bienes exentos (con derecho a devolución de IVA descontable).',
            ],
            [
                'code' => 'IVA-EXC',
                'name' => 'IVA Excluido',
                'type' => 'vat',
                'rate' => 0.0000,
                'sale_account_code' => null,
                'purchase_account_code' => null,
                'minimum_base' => 0,
                'applies_to' => 'both',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Bienes y servicios excluidos del IVA (no genera ni descuenta).',
            ],

            // ===== INC =====
            [
                'code' => 'INC-8',
                'name' => 'INC 8% (Restaurantes)',
                'type' => 'consumption_tax',
                'rate' => 8.0000,
                'sale_account_code' => '240805',
                'purchase_account_code' => null,
                'minimum_base' => 0,
                'applies_to' => 'sale',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Impuesto Nacional al Consumo restaurantes y bares.',
            ],

            // ===== Retención en la fuente (Renta) =====
            [
                'code' => 'RTF-COMPRAS-25',
                'name' => 'ReteFuente Compras 2.5%',
                'type' => 'income_withholding',
                'rate' => 2.5000,
                'sale_account_code' => '135515',     // anticipo (cuando nos retienen)
                'purchase_account_code' => '236540', // por pagar (cuando retenemos)
                'minimum_base' => 1397000, // 27 UVT aprox.
                'applies_to' => 'both',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Retención en la fuente por compras de bienes — declarantes.',
            ],
            [
                'code' => 'RTF-SERVICIOS-4',
                'name' => 'ReteFuente Servicios 4%',
                'type' => 'income_withholding',
                'rate' => 4.0000,
                'sale_account_code' => '135515',
                'purchase_account_code' => '236525',
                'minimum_base' => 207000, // 4 UVT
                'applies_to' => 'both',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Retención en la fuente por servicios — declarantes.',
            ],
            [
                'code' => 'RTF-SERVICIOS-6',
                'name' => 'ReteFuente Servicios 6%',
                'type' => 'income_withholding',
                'rate' => 6.0000,
                'sale_account_code' => '135515',
                'purchase_account_code' => '236525',
                'minimum_base' => 207000,
                'applies_to' => 'both',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Retención en la fuente por servicios — no declarantes.',
            ],
            [
                'code' => 'RTF-HONORARIOS-11',
                'name' => 'ReteFuente Honorarios 11%',
                'type' => 'income_withholding',
                'rate' => 11.0000,
                'sale_account_code' => '135515',
                'purchase_account_code' => '236515',
                'minimum_base' => 0,
                'applies_to' => 'both',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Retención en la fuente por honorarios — declarantes y personas jurídicas.',
            ],
            [
                'code' => 'RTF-HONORARIOS-10',
                'name' => 'ReteFuente Honorarios 10%',
                'type' => 'income_withholding',
                'rate' => 10.0000,
                'sale_account_code' => '135515',
                'purchase_account_code' => '236515',
                'minimum_base' => 0,
                'applies_to' => 'both',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Retención en la fuente por honorarios — no declarantes.',
            ],
            [
                'code' => 'RTF-ARRENDAMIENTO-35',
                'name' => 'ReteFuente Arrendamientos 3.5%',
                'type' => 'income_withholding',
                'rate' => 3.5000,
                'sale_account_code' => '135515',
                'purchase_account_code' => '236530',
                'minimum_base' => 1397000, // 27 UVT
                'applies_to' => 'both',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Retención en la fuente por arrendamiento de bienes inmuebles.',
            ],

            // ===== ReteIVA =====
            [
                'code' => 'RTI-15',
                'name' => 'ReteIVA 15%',
                'type' => 'vat_withholding',
                'rate' => 15.0000,
                'sale_account_code' => '135517',  // IVA retenido (anticipo)
                'purchase_account_code' => '2367', // IVA retenido por pagar
                'minimum_base' => 0,
                'applies_to' => 'both',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Retención del 15% sobre el valor del IVA en operaciones gravadas.',
            ],

            // ===== ReteICA (ejemplo Bogotá actividad comercial) =====
            [
                'code' => 'RTC-BOG-414',
                'name' => 'ReteICA Bogotá 4.14×1000 (Comercio)',
                'type' => 'ica_withholding',
                'rate' => 0.4140,
                'sale_account_code' => '135518',
                'purchase_account_code' => '2368',
                'minimum_base' => 0,
                'applies_to' => 'both',
                'is_default_for_sale' => false,
                'is_default_for_purchase' => false,
                'description' => 'Retención de ICA Bogotá D.C. — actividad comercial 4.14 por mil.',
            ],
        ];
    }
}
