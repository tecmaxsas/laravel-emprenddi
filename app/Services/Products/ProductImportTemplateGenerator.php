<?php

namespace App\Services\Products;

use App\Models\Account;
use App\Models\Category;
use App\Models\Location;
use App\Models\Tax;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Genera la plantilla XLSX de importacion masiva de productos.
 *
 * 5 hojas:
 *   1. Instrucciones — guia paso a paso
 *   2. Productos — headers + 5 filas de ejemplo (una por tipo)
 *   3. Categorias (ref) — codigos y nombres actuales de la empresa
 *   4. Impuestos (ref) — codigos y tasas
 *   5. Cuentas (ref) — cuentas contables comunes por naturaleza
 *
 * El engine que consume esta plantilla es ProductImportEngine.
 */
class ProductImportTemplateGenerator
{
    /** Columnas de la hoja "Inventario Inicial" — orden exacto. */
    public const STOCK_COLUMNS = [
        'product_code',
        'location_code',
        'qty',
        'unit_cost',
    ];

    /** Columnas de la hoja "Productos" — orden exacto. */
    public const COLUMNS = [
        'code',
        'name',
        'type',
        'variation_of_code',
        'category_code',
        'brand',
        'unit',
        'description',
        'barcode',
        'is_sellable',
        'is_purchasable',
        'track_inventory',
        'tracks_serials',
        'warranty_days',
        'sale_price',
        'sale_price_includes_tax',
        'sale_tax_code',
        'purchase_price',
        'purchase_tax_code',
        'sale_account_code',
        'purchase_account_code',
        'inventory_account_code',
        'cost_account_code',
        'active',
    ];

    public function stream(int $companyId): callable
    {
        return function () use ($companyId) {
            $options = new Options();
            $writer = new Writer($options);
            $writer->openToFile('php://output');

            $this->writeInstructionsSheet($writer);
            $this->writeProductsSheet($writer);
            $this->writeInitialStockSheet($writer);
            $this->writeCategoriesRefSheet($writer, $companyId);
            $this->writeTaxesRefSheet($writer, $companyId);
            $this->writeAccountsRefSheet($writer, $companyId);
            $this->writeLocationsRefSheet($writer, $companyId);

            $writer->close();
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Hoja 1 — Instrucciones                                              */
    /* ------------------------------------------------------------------ */

    protected function writeInstructionsSheet(Writer $writer): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Instrucciones');

        $title = (new Style())->setFontBold()->setFontSize(16)->setFontColor('4F46E5');
        $section = (new Style())->setFontBold()->setFontSize(12)->setBackgroundColor('EEF2FF');
        $warn = (new Style())->setFontBold()->setFontColor('B45309');

        $lines = [
            ['title', 'Importación masiva de productos'],
            ['blank', ''],
            ['text', 'Esta plantilla permite crear/actualizar productos en lote. Los productos existentes (mismo código) se ACTUALIZAN; los nuevos se CREAN.'],
            ['blank', ''],
            ['section', 'Reglas generales'],
            ['text', '• Rellena solo la hoja "Productos". No modifiques el orden ni los nombres de las columnas.'],
            ['text', '• Las hojas "Categorías", "Impuestos" y "Cuentas" son solo de referencia — copia los códigos.'],
            ['text', '• Los booleanos (is_sellable, active, etc.) aceptan: SI / NO, 1 / 0, TRUE / FALSE. Vacío = valor por defecto.'],
            ['text', '• Los precios se ingresan como números (sin símbolo $, sin separadores de miles). Usa punto para decimales.'],
            ['text', '• Los campos con * son obligatorios.'],
            ['blank', ''],
            ['section', 'Columnas'],
            ['text', 'code                      SKU único del producto (ej. GYO001). OPCIONAL:'],
            ['text', '                          si lo dejas vacío, el sistema genera uno automáticamente'],
            ['text', '                          con formato PROD-0001, PROD-0002, ...'],
            ['text', 'name*                     Nombre del producto'],
            ['text', 'type*                     Uno de: good | service | kit | consumable | variable'],
            ['text', '                          good = bien físico | service = servicio | kit = combo'],
            ['text', '                          consumable = insumo interno | variable = padre con variantes'],
            ['text', 'variation_of_code         Si esta fila es una variante, código o NOMBRE del PADRE'],
            ['text', '                          tipo=variable. El padre debe existir o venir antes en la hoja.'],
            ['text', '                          Si el padre no tiene código (auto-generado), puedes'],
            ['text', '                          referenciarlo por su name (ej. "Camisa Polo").'],
            ['text', 'category_code             Código de la categoría (ver hoja "Categorías"). Opcional.'],
            ['text', 'brand                     Marca. Opcional.'],
            ['text', 'unit                      unit | kg | g | l | ml | m | cm | box | pack | hour | day | service (default: unit)'],
            ['text', 'description               Descripción larga.'],
            ['text', 'barcode                   EAN/UPC. Opcional.'],
            ['text', 'is_sellable               SI/NO — ¿se puede vender? (default SI)'],
            ['text', 'is_purchasable            SI/NO — ¿se puede comprar? (default SI, NO para service)'],
            ['text', 'track_inventory           SI/NO — ¿controla stock? (default SI para good, NO para service/kit/variable)'],
            ['text', 'tracks_serials            SI/NO — ¿cada unidad tiene serial único?'],
            ['text', 'warranty_days             Días de garantía (0 = sin garantía).'],
            ['text', 'sale_price                Precio de venta al público.'],
            ['text', 'sale_price_includes_tax   SI/NO — ¿el precio ya incluye IVA?'],
            ['text', 'sale_tax_code             Código del impuesto de venta (ver hoja "Impuestos"). Opcional.'],
            ['text', 'purchase_price            Precio de compra (opcional).'],
            ['text', 'purchase_tax_code         Código del impuesto de compra. Opcional.'],
            ['text', 'sale_account_code         Cuenta contable de ingreso por venta (ej. 4135). Opcional — hereda de categoría.'],
            ['text', 'purchase_account_code     Cuenta de compra. Opcional.'],
            ['text', 'inventory_account_code    Cuenta de inventario (ej. 1435). Opcional.'],
            ['text', 'cost_account_code         Cuenta de costo de venta (ej. 6135). Opcional.'],
            ['text', 'active                    SI/NO — ¿producto activo? (default SI)'],
            ['blank', ''],
            ['section', 'Cómo cargar productos variables (con variantes)'],
            ['text', '1. Crea PRIMERO una fila con type = variable (el padre).'],
            ['text', '   Ejemplo: code=CAMISA, name=Camisa Polo, type=variable.'],
            ['text', '2. Luego crea una fila por cada variante con type = good (o service) y'],
            ['text', '   variation_of_code = CAMISA (o el nombre del padre si no le pusiste code).'],
            ['text', '   Ejemplo: code=CAMISA-M-AZUL, name=Camisa Polo Talla M Azul,'],
            ['text', '   type=good, variation_of_code=CAMISA.'],
            ['text', '3. Las variantes son las que realmente se venden y llevan stock.'],
            ['blank', ''],
            ['section', '¿No tienes códigos SKU?'],
            ['text', 'Deja la columna "code" vacía en todas las filas — el sistema genera'],
            ['text', 'códigos únicos PROD-0001, PROD-0002, etc. Para variantes, referencia'],
            ['text', 'al padre por su nombre en variation_of_code.'],
            ['text', 'En la hoja "Inventario Inicial" puedes usar code o name para product_code.'],
            ['blank', ''],
            ['warn', 'IMPORTANTE: si un producto con el mismo code ya existe, se ACTUALIZARÁN sus campos con los datos del archivo. Deja vacío lo que NO quieras cambiar.'],
            ['blank', ''],
            ['section', 'Inventario inicial (opcional)'],
            ['text', 'La hoja "Inventario Inicial" permite cargar el saldo de arranque de cada producto por sede.'],
            ['text', 'Columnas:'],
            ['text', '  product_code*    Código del producto (debe existir en la hoja "Productos" o en la BD)'],
            ['text', '  location_code*   Código de la sede (ver hoja "Sedes")'],
            ['text', '  qty*             Cantidad (número > 0)'],
            ['text', '  unit_cost*       Costo unitario (para valorar el inventario)'],
            ['text', 'Reglas:'],
            ['text', '  • Solo productos con track_inventory = SI pueden tener stock inicial.'],
            ['text', '  • Una fila por combinación (producto, sede). Puedes cargar el mismo producto en varias sedes.'],
            ['text', '  • Al importar te pediremos la CUENTA CONTRAPARTIDA (crédito del asiento). Sugerido: 3705 — Resultados de ejercicios anteriores.'],
            ['text', '  • Se crea una apertura de inventario (SI-###) por cada sede con las líneas correspondientes, y se contabiliza automáticamente.'],
            ['blank', ''],
            ['section', 'Después de importar'],
            ['text', '• El sistema valida cada fila y muestra un preview con errores antes de confirmar.'],
            ['text', '• Solo se guardan los productos SI presionas "Confirmar importación" tras revisar el preview.'],
            ['text', '• Los precios por sede (min/max stock, punto reorden) se configuran DESPUÉS desde cada producto.'],
        ];

        foreach ($lines as [$kind, $text]) {
            $row = match ($kind) {
                'title' => Row::fromValues([$text], $title),
                'section' => Row::fromValues([$text], $section),
                'warn' => Row::fromValues([$text], $warn),
                'blank' => Row::fromValues([''], null),
                default => Row::fromValues([$text], null),
            };
            $writer->addRow($row);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Hoja 2 — Productos (headers + ejemplos)                            */
    /* ------------------------------------------------------------------ */

    protected function writeProductsSheet(Writer $writer): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Productos');

        $headerStyle = (new Style())
            ->setFontBold()
            ->setBackgroundColor('4F46E5')
            ->setFontColor('FFFFFF');

        $writer->addRow(Row::fromValues(self::COLUMNS, $headerStyle));

        // 6 ejemplos: 1 por tipo + 2 variantes de un variable
        $examples = [
            // Bien físico simple
            [
                'AGUA600', 'Agua Cristal 600ml', 'good', '', 'BEB', 'Cristal', 'unit',
                'Botella de agua 600ml', '7702186000123', 'SI', 'SI', 'SI', 'NO', 0,
                3000, 'SI', 'IVA19', 1800, 'IVA19', '4135', '6205', '1435', '6135', 'SI',
            ],
            // Servicio
            [
                'INSTALACION', 'Instalación técnica', 'service', '', 'SRV', '', 'hour',
                'Servicio de instalación por hora', '', 'SI', 'NO', 'NO', 'NO', 0,
                80000, 'SI', 'IVA19', 0, '', '4145', '', '', '', 'SI',
            ],
            // Consumable
            [
                'RESMA', 'Resma papel carta', 'consumable', '', 'INS', 'Reprograf', 'pack',
                'Consumo interno oficina', '', 'NO', 'SI', 'SI', 'NO', 0,
                0, 'NO', '', 22000, 'IVA19', '', '5140', '1455', '', 'SI',
            ],
            // Variable — PADRE (aparece antes que sus variantes)
            [
                'CAMISA-POLO', 'Camisa Polo (variantes)', 'variable', '', 'ROPA', 'MyBrand', 'unit',
                'Camisa polo con variantes por talla y color', '', 'NO', 'NO', 'NO', 'NO', 0,
                0, 'NO', '', 0, '', '4135', '6205', '1435', '6135', 'SI',
            ],
            // Variante 1
            [
                'CAMISA-M-AZUL', 'Camisa Polo M Azul', 'good', 'CAMISA-POLO', 'ROPA', 'MyBrand', 'unit',
                'Talla M color Azul', '7702186000201', 'SI', 'SI', 'SI', 'NO', 0,
                85000, 'SI', 'IVA19', 42000, 'IVA19', '', '', '', '', 'SI',
            ],
            // Variante 2
            [
                'CAMISA-L-ROJO', 'Camisa Polo L Rojo', 'good', 'CAMISA-POLO', 'ROPA', 'MyBrand', 'unit',
                'Talla L color Rojo', '7702186000202', 'SI', 'SI', 'SI', 'NO', 0,
                85000, 'SI', 'IVA19', 42000, 'IVA19', '', '', '', '', 'SI',
            ],
            // Ejemplo SIN code — el sistema auto-genera PROD-XXXX
            [
                '', 'Café molido 500g', 'good', '', 'BEB', 'Juan Valdez', 'unit',
                'Bolsa de café molido 500g', '', 'SI', 'SI', 'SI', 'NO', 0,
                18000, 'SI', 'IVA19', 12000, 'IVA19', '', '', '', '', 'SI',
            ],
            // Ejemplo padre variable SIN code + variantes referenciando por NAME
            [
                '', 'Zapato deportivo', 'variable', '', 'CALZ', 'RunFast', 'unit',
                'Zapato con variantes por talla', '', 'NO', 'NO', 'NO', 'NO', 0,
                0, 'NO', '', 0, '', '', '', '', '', 'SI',
            ],
            [
                '', 'Zapato deportivo T38', 'good', 'Zapato deportivo', 'CALZ', 'RunFast', 'unit',
                'Talla 38', '', 'SI', 'SI', 'SI', 'NO', 0,
                180000, 'SI', 'IVA19', 90000, 'IVA19', '', '', '', '', 'SI',
            ],
        ];

        foreach ($examples as $ex) {
            $writer->addRow(Row::fromValues($ex));
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Hoja 3 — Inventario Inicial (headers + ejemplos)                   */
    /* ------------------------------------------------------------------ */

    protected function writeInitialStockSheet(Writer $writer): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Inventario Inicial');

        $headerStyle = (new Style())
            ->setFontBold()
            ->setBackgroundColor('16A34A')
            ->setFontColor('FFFFFF');

        $writer->addRow(Row::fromValues(self::STOCK_COLUMNS, $headerStyle));

        // Ejemplos coherentes con los productos de la hoja "Productos"
        $examples = [
            ['AGUA600',       'PRINCIPAL', 50,  1800],   // 50 botellas en la principal
            ['AGUA600',       'SUCURSAL',  30,  1800],   // mismo producto, otra sede
            ['CAMISA-M-AZUL', 'PRINCIPAL', 20,  42000],
            ['CAMISA-L-ROJO', 'PRINCIPAL', 15,  42000],
        ];

        foreach ($examples as $ex) {
            $writer->addRow(Row::fromValues($ex));
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Hoja 4-7 — Referencias (categorias, impuestos, cuentas, sedes)     */
    /* ------------------------------------------------------------------ */

    protected function writeCategoriesRefSheet(Writer $writer, int $companyId): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Categorias (ref)');
        $header = (new Style())->setFontBold()->setBackgroundColor('E0E7FF');
        $writer->addRow(Row::fromValues(['code', 'name', 'padre'], $header));

        $rows = Category::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'parent_id']);

        $byId = $rows->keyBy('id');
        foreach ($rows as $c) {
            $writer->addRow(Row::fromValues([
                $c->code ?? '',
                $c->name,
                $c->parent_id ? ($byId[$c->parent_id]->name ?? '') : '',
            ]));
        }
    }

    protected function writeTaxesRefSheet(Writer $writer, int $companyId): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Impuestos (ref)');
        $header = (new Style())->setFontBold()->setBackgroundColor('E0E7FF');
        $writer->addRow(Row::fromValues(['code', 'name', 'rate', 'type'], $header));

        Tax::query()
            ->where('company_id', $companyId)
            ->orderBy('type')->orderBy('rate')
            ->get(['code', 'name', 'rate', 'type'])
            ->each(function ($t) use ($writer) {
                $writer->addRow(Row::fromValues([
                    $t->code ?? '',
                    $t->name,
                    (float) $t->rate,
                    $t->type ?? '',
                ]));
            });
    }

    protected function writeAccountsRefSheet(Writer $writer, int $companyId): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Cuentas (ref)');
        $header = (new Style())->setFontBold()->setBackgroundColor('E0E7FF');
        $writer->addRow(Row::fromValues(['code', 'name', 'uso sugerido'], $header));

        // Solo las cuentas que aceptan movimientos y del rango 13xx, 14xx, 41xx, 42xx, 51xx, 52xx, 6xxx
        Account::query()
            ->where('company_id', $companyId)
            ->where('accepts_movements', true)
            ->where('active', true)
            ->where(function ($q) {
                $q->where('code', 'like', '13%')
                    ->orWhere('code', 'like', '14%')
                    ->orWhere('code', 'like', '41%')
                    ->orWhere('code', 'like', '42%')
                    ->orWhere('code', 'like', '51%')
                    ->orWhere('code', 'like', '52%')
                    ->orWhere('code', 'like', '6%');
            })
            ->orderBy('code')
            ->limit(120)
            ->get(['code', 'name'])
            ->each(function ($a) use ($writer) {
                $hint = match (true) {
                    str_starts_with($a->code, '13') => 'CxC (opcional)',
                    str_starts_with($a->code, '14') => 'Inventory',
                    str_starts_with($a->code, '41') => 'Ingreso por venta',
                    str_starts_with($a->code, '42') => 'Otros ingresos',
                    str_starts_with($a->code, '51'), str_starts_with($a->code, '52') => 'Gasto',
                    str_starts_with($a->code, '6') => 'Costo de venta',
                    default => '',
                };
                $writer->addRow(Row::fromValues([$a->code, $a->name, $hint]));
            });
    }

    protected function writeLocationsRefSheet(Writer $writer, int $companyId): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Sedes (ref)');
        $header = (new Style())->setFontBold()->setBackgroundColor('E0E7FF');
        $writer->addRow(Row::fromValues(['code', 'name', 'is_main'], $header));

        Location::query()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get(['code', 'name', 'is_main'])
            ->each(function ($l) use ($writer) {
                $writer->addRow(Row::fromValues([
                    $l->code ?? '',
                    $l->name,
                    $l->is_main ? 'SI' : '',
                ]));
            });
    }
}
