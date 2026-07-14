<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;

/**
 * Helper para la configuracion de impresion de etiquetas con codigo
 * de barras. Todo se guarda en company.settings.labels.*
 *
 *   LabelsSettings::enabled()           toggle global
 *   LabelsSettings::fields()            array de campos a incluir
 *   LabelsSettings::dimensions()        ['width' => 50, 'height' => 30] mm
 *   LabelsSettings::barcodeType()       'CODE128' | 'EAN13' | 'CODE39' | 'ITF'
 *   LabelsSettings::columnsPerSheet()   etiquetas por fila cuando se imprime
 *                                       en hoja completa (default 3)
 *   LabelsSettings::config()            todo el bloque como array
 */
class LabelsSettings
{
    /** Campos disponibles y su etiqueta legible. */
    public const AVAILABLE_FIELDS = [
        'name' => 'Nombre',
        'code' => 'SKU / Código',
        'barcode' => 'Código de barras',
        'price' => 'Precio de venta',
        'category' => 'Categoría',
        'brand' => 'Marca',
        'location' => 'Ubicación física',
        'company_name' => 'Nombre de la empresa',
    ];

    public const DEFAULT_FIELDS = ['name', 'code', 'barcode', 'price'];

    public const BARCODE_TYPES = [
        'CODE128' => 'CODE128 (más común, alfanumérico)',
        'EAN13' => 'EAN-13 (retail, exige 12 dígitos)',
        'CODE39' => 'CODE39 (alfanumérico simple)',
        'ITF14' => 'ITF-14 (cajas / GTIN)',
    ];

    public const SIZE_PRESETS = [
        '50x30' => '50 × 30 mm (estándar de estante)',
        '40x20' => '40 × 20 mm (pequeña)',
        '60x40' => '60 × 40 mm (grande, con precio destacado)',
        '80x50' => '80 × 50 mm (envío / caja)',
        'custom' => 'Personalizado',
    ];

    /**
     * Modo de impresión:
     *  - sheet: hoja completa A4 con grilla de N columnas (Avery, etiquetas
     *    autoadhesivas en hoja).
     *  - roll:  UNA etiqueta por página con dimensiones exactas del label
     *    (impresoras térmicas de rollo tipo Zebra, Brother QL, etc.).
     *    El navegador manda 1 etiqueta = 1 "página" al driver de la impresora
     *    con margen 0 para evitar recortes.
     */
    public const PRINT_MODES = [
        'sheet' => 'Hoja completa A4 (Avery / autoadhesivas)',
        'roll' => 'Rollo continuo (impresora térmica de etiquetas)',
    ];

    public static function enabled(?Company $company = null): bool
    {
        $company ??= self::currentCompany();
        if (! $company) return false;
        return (bool) data_get($company->settings, 'labels.enabled', false);
    }

    public static function config(?Company $company = null): array
    {
        $company ??= self::currentCompany();
        $settings = $company?->settings ?? [];
        return [
            'enabled' => (bool) data_get($settings, 'labels.enabled', false),
            'fields' => (array) data_get($settings, 'labels.fields', self::DEFAULT_FIELDS),
            'width_mm' => (int) data_get($settings, 'labels.width_mm', 50),
            'height_mm' => (int) data_get($settings, 'labels.height_mm', 30),
            'barcode_type' => (string) data_get($settings, 'labels.barcode_type', 'CODE128'),
            'columns_per_sheet' => (int) data_get($settings, 'labels.columns_per_sheet', 3),
            'show_currency_symbol' => (bool) data_get($settings, 'labels.show_currency_symbol', true),
            'print_mode' => (string) data_get($settings, 'labels.print_mode', 'sheet'),
        ];
    }

    public static function fields(?Company $company = null): array
    {
        return self::config($company)['fields'];
    }

    public static function dimensions(?Company $company = null): array
    {
        $c = self::config($company);
        return ['width' => $c['width_mm'], 'height' => $c['height_mm']];
    }

    public static function barcodeType(?Company $company = null): string
    {
        return self::config($company)['barcode_type'];
    }

    public static function columnsPerSheet(?Company $company = null): int
    {
        return self::config($company)['columns_per_sheet'];
    }

    protected static function currentCompany(): ?Company
    {
        $companyId = Auth::user()?->company_id;
        return $companyId ? Company::find($companyId) : null;
    }
}
