<?php

namespace App\Services\ThirdParties;

use App\Models\Account;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Genera la plantilla XLSX de importacion masiva de terceros.
 *
 * 4 hojas:
 *   1. Instrucciones — guia paso a paso
 *   2. Terceros — headers + ejemplos (cliente, proveedor, ambos)
 *   3. Cuentas CxC/CxP (ref) — codigos y nombres actuales de la empresa
 *   4. Codigos DIAN (ref) — tipos de documento, personas, regimenes,
 *      responsabilidades tributarias
 *
 * El engine que consume esta plantilla es ThirdPartyImportEngine.
 */
class ThirdPartyImportTemplateGenerator
{
    /** Columnas de la hoja "Terceros" — orden exacto. */
    public const COLUMNS = [
        'document_number',
        'document_type',
        'person_type',
        'name',
        'legal_name',
        'trade_name',
        'first_name',
        'middle_name',
        'last_name',
        'second_last_name',
        'dv',
        'is_customer',
        'is_supplier',
        'is_employee',
        'is_other',
        'email',
        'phone',
        'mobile',
        'address',
        'city',
        'department',
        'country',
        'postal_code',
        'contact_person',
        'contact_phone',
        'regime_type',
        'tax_responsibilities',
        'is_self_withholder',
        'is_iva_withholder',
        'is_ica_withholder',
        'receivable_account_code',
        'payable_account_code',
        'credit_limit',
        'credit_days',
        // Lo que el cliente ya debia al migrar desde otro sistema. Abre su
        // estado de cuenta y no corresponde a ninguna factura de aqui.
        'opening_balance',
        'opening_balance_date',
        'payment_terms_days',
        'website',
        'notes',
        'active',
    ];

    public function stream(int $companyId): callable
    {
        return function () use ($companyId) {
            $options = new Options();
            $writer = new Writer($options);
            $writer->openToFile('php://output');

            $this->writeInstructionsSheet($writer);
            $this->writeThirdPartiesSheet($writer);
            $this->writeAccountsRefSheet($writer, $companyId);
            $this->writeDianCodesRefSheet($writer);

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
            ['title', 'Importación masiva de terceros (clientes y proveedores)'],
            ['blank', ''],
            ['text', 'Esta plantilla permite crear/actualizar terceros en lote. Un mismo tercero puede ser cliente Y proveedor al mismo tiempo — marca los checkboxes según corresponda.'],
            ['text', 'Los terceros existentes (mismo document_number) se ACTUALIZAN; los nuevos se CREAN.'],
            ['blank', ''],
            ['section', 'Reglas generales'],
            ['text', '• Rellena solo la hoja "Terceros". No modifiques el orden ni los nombres de las columnas.'],
            ['text', '• Las hojas "Cuentas" y "Códigos DIAN" son solo de referencia — copia los códigos.'],
            ['text', '• Los booleanos aceptan: SI / NO, 1 / 0, TRUE / FALSE. Vacío = valor por defecto.'],
            ['text', '• Los campos con * son obligatorios.'],
            ['blank', ''],
            ['section', 'Columnas obligatorias'],
            ['text', 'document_number*         Número de identificación (cédula, NIT, pasaporte, etc.)'],
            ['text', 'document_type*           Uno de: cc | ce | ti | nit | pasaporte | rut | nuip | die'],
            ['text', 'person_type*             natural (persona natural) | juridica (empresa)'],
            ['text', 'name*                    Nombre comercial / razón social — el que se ve en el sistema'],
            ['blank', ''],
            ['section', 'Marcadores de rol (al menos uno debe ser SI)'],
            ['text', 'is_customer              SI si es cliente (se le vende) — default NO'],
            ['text', 'is_supplier              SI si es proveedor (se le compra) — default NO'],
            ['text', 'is_employee              SI si es empleado — default NO'],
            ['text', 'is_other                 SI si es solo un contacto (transportador, etc.) — default NO'],
            ['blank', ''],
            ['section', 'Datos opcionales'],
            ['text', 'legal_name               Razón social legal (típico para NIT — diferente al nombre comercial)'],
            ['text', 'trade_name               Nombre comercial adicional'],
            ['text', 'first_name/middle_name/  Solo para persona natural — se llenan si prefieres tener los'],
            ['text', 'last_name/second_last_   nombres separados. Si dejas todo vacío usa "name".'],
            ['text', 'dv                       Dígito de verificación del NIT (1-9). Solo si document_type=nit.'],
            ['blank', ''],
            ['section', 'Contacto'],
            ['text', 'email, phone, mobile     Datos de contacto'],
            ['text', 'address, city,           Dirección física'],
            ['text', 'department, country'],
            ['text', 'contact_person           Persona de contacto en la empresa cliente'],
            ['text', 'contact_phone            Teléfono de esa persona'],
            ['blank', ''],
            ['section', 'Datos tributarios / DIAN'],
            ['text', 'regime_type              comun (responsable IVA) | no_responsable_iva | gran_contribuyente | simplificado'],
            ['text', 'tax_responsibilities     Códigos DIAN separados por punto y coma. Ej: "O-13;O-47"'],
            ['text', '                         Códigos disponibles: O-13, O-15, O-23, O-47, R-99-PN, ZA'],
            ['text', 'is_self_withholder       SI/NO — es autorretenedor'],
            ['text', 'is_iva_withholder        SI/NO — retiene IVA'],
            ['text', 'is_ica_withholder        SI/NO — retiene ICA'],
            ['blank', ''],
            ['section', 'Contabilidad y crédito'],
            ['text', 'receivable_account_code  Cuenta CxC específica del cliente. Opcional — usa 1305 por defecto.'],
            ['text', 'payable_account_code     Cuenta CxP específica del proveedor. Opcional — usa 2205 por defecto.'],
            ['text', 'credit_limit             Cupo máximo de crédito ($). Solo para clientes.'],
            ['text', 'credit_days              Días de crédito. Solo para clientes.'],
            ['text', 'payment_terms_days       Plazo de pago (para plazos de compra en proveedores)'],
            ['blank', ''],
            ['warn', 'IMPORTANTE: el sistema busca por document_number para decidir si CREA o ACTUALIZA. Si ya existe un tercero con ese número, se ACTUALIZAN sus campos con los datos del archivo. Deja vacío lo que NO quieras cambiar.'],
            ['blank', ''],
            ['section', 'Después de importar'],
            ['text', '• El sistema valida cada fila y muestra un preview con errores antes de confirmar.'],
            ['text', '• Solo se guardan los terceros SI presionas "Confirmar importación" tras revisar el preview.'],
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
    /*  Hoja 2 — Terceros (headers + ejemplos)                             */
    /* ------------------------------------------------------------------ */

    protected function writeThirdPartiesSheet(Writer $writer): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Terceros');

        $headerStyle = (new Style())
            ->setFontBold()
            ->setBackgroundColor('4F46E5')
            ->setFontColor('FFFFFF');

        $writer->addRow(Row::fromValues(self::COLUMNS, $headerStyle));

        // Ejemplos: 1 cliente natural, 1 proveedor juridica, 1 ambos, 1 cliente empresa
        $examples = [
            // Cliente persona natural (mínimo)
            [
                '79456123', 'cc', 'natural',
                'Juan Pérez García', '', '', 'Juan', '', 'Pérez', 'García', '',
                'SI', 'NO', 'NO', 'NO',
                'juan.perez@correo.co', '', '3001234567',
                'Cra 10 #20-30', 'Bogotá', 'Cundinamarca', 'CO', '',
                '', '', 'no_responsable_iva', 'R-99-PN',
                'NO', 'NO', 'NO',
                '', '', 500000, 30, 0,
                '', '', 'SI',
            ],
            // Proveedor persona jurídica
            [
                '900111222', 'nit', 'juridica',
                'Acme S.A.S.', 'Acme Sociedad por Acciones Simplificada', '',
                '', '', '', '', '3',
                'NO', 'SI', 'NO', 'NO',
                'facturacion@acme.co', '6013456789', '',
                'Cra 15 #93-45', 'Bogotá', 'Cundinamarca', 'CO', '',
                'María López', '3009876543',
                'comun', 'O-13;O-15',
                'NO', 'NO', 'NO',
                '', '', 0, 0, 30,
                'https://acme.co', 'Proveedor de insumos', 'SI',
            ],
            // Cliente Y proveedor (empresa que vende y compra)
            [
                '901222333', 'nit', 'juridica',
                'Distribuciones Andinas', 'Distribuciones Andinas SAS', 'Distri Andinas',
                '', '', '', '', '5',
                'SI', 'SI', 'NO', 'NO',
                'comercial@distri.co', '6017654321', '',
                'Calle 72 #10-40', 'Medellín', 'Antioquia', 'CO', '',
                '', '',
                'comun', 'ZA',
                'NO', 'NO', 'NO',
                '', '', 5000000, 30, 30,
                '', 'Cliente y proveedor simultáneo', 'SI',
            ],
            // Cliente Consumidor Final (para retail que factura anónimo)
            [
                '222222222', 'cc', 'natural',
                'Consumidor Final', '', '', '', '', '', '', '',
                'SI', 'NO', 'NO', 'NO',
                '', '', '',
                'Sin dirección', '', '', 'CO', '',
                '', '',
                'no_responsable_iva', 'R-99-PN',
                'NO', 'NO', 'NO',
                '', '', 0, 0, 0,
                '', '', 'SI',
            ],
        ];

        foreach ($examples as $ex) {
            $writer->addRow(Row::fromValues($ex));
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Hoja 3 — Cuentas CxC / CxP (ref)                                   */
    /* ------------------------------------------------------------------ */

    protected function writeAccountsRefSheet(Writer $writer, int $companyId): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Cuentas (ref)');
        $header = (new Style())->setFontBold()->setBackgroundColor('E0E7FF');
        $writer->addRow(Row::fromValues(['code', 'name', 'uso sugerido'], $header));

        // Cuentas del rango 13xx (CxC clientes) y 22xx (CxP proveedores)
        Account::query()
            ->where('company_id', $companyId)
            ->where('accepts_movements', true)
            ->where('active', true)
            ->where(function ($q) {
                $q->where('code', 'like', '13%')
                    ->orWhere('code', 'like', '22%');
            })
            ->orderBy('code')
            ->limit(80)
            ->get(['code', 'name'])
            ->each(function ($a) use ($writer) {
                $hint = str_starts_with($a->code, '13')
                    ? 'CxC (clientes)'
                    : 'CxP (proveedores)';
                $writer->addRow(Row::fromValues([$a->code, $a->name, $hint]));
            });
    }

    /* ------------------------------------------------------------------ */
    /*  Hoja 4 — Códigos DIAN (ref)                                        */
    /* ------------------------------------------------------------------ */

    protected function writeDianCodesRefSheet(Writer $writer): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName('Codigos DIAN (ref)');
        $sectionStyle = (new Style())->setFontBold()->setBackgroundColor('EEF2FF')->setFontSize(11);
        $header = (new Style())->setFontBold()->setBackgroundColor('E0E7FF');

        // Document types
        $writer->addRow(Row::fromValues(['TIPOS DE DOCUMENTO'], $sectionStyle));
        $writer->addRow(Row::fromValues(['code', 'nombre'], $header));
        foreach (\App\Models\ThirdParty::DOCUMENT_TYPES as $code => $name) {
            $writer->addRow(Row::fromValues([$code, $name]));
        }

        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['TIPOS DE PERSONA'], $sectionStyle));
        $writer->addRow(Row::fromValues(['code', 'nombre'], $header));
        foreach (\App\Models\ThirdParty::PERSON_TYPES as $code => $name) {
            $writer->addRow(Row::fromValues([$code, $name]));
        }

        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['RÉGIMEN TRIBUTARIO'], $sectionStyle));
        $writer->addRow(Row::fromValues(['code', 'nombre'], $header));
        foreach (\App\Models\ThirdParty::REGIME_TYPES as $code => $name) {
            $writer->addRow(Row::fromValues([$code, $name]));
        }

        $writer->addRow(Row::fromValues(['']));
        $writer->addRow(Row::fromValues(['RESPONSABILIDADES TRIBUTARIAS (separar por ; en la celda)'], $sectionStyle));
        $writer->addRow(Row::fromValues(['code', 'nombre'], $header));
        foreach (\App\Models\ThirdParty::TAX_RESPONSIBILITIES as $code => $name) {
            $writer->addRow(Row::fromValues([$code, $name]));
        }
    }
}
