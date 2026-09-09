<?php

namespace App\Services\Audit;

use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Category;
use App\Models\CreditDebitNote;
use App\Models\CustomerAdvance;
use App\Models\DeliveryNote;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Models\GiftCard;
use App\Models\InventoryAdjustment;
use App\Models\InventoryOpening;
use App\Models\InventoryTransfer;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\PartnerMovement;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PayrollPeriod;
use App\Models\PayrollSettlement;
use App\Models\Product;
use App\Models\ProductCatalog;
use App\Models\Promotion;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Qué se audita y cómo se lee.
 *
 * Deliberadamente NO se auditan todos los modelos. Registrar cada línea de cada
 * factura y cada movimiento de inventario llenaría la bitácora de ruido —cientos
 * de filas por venta— y el administrador dejaría de mirarla, que es la única
 * forma en que una auditoría falla del todo. Aquí están los objetos por los que
 * alguien pregunta: documentos, dinero, catálogo, usuarios y permisos.
 *
 * Las líneas de un documento se ven en el documento; el kardex se ve en el
 * kardex. Lo que faltaba era saber quién tocó qué y cuándo.
 *
 * Para auditar un modelo más, basta agregarlo a MODELOS con su nombre en
 * español. El observador se engancha solo.
 */
class AuditRegistry
{
    /**
     * Modelo => cómo se llama en la pantalla.
     *
     * @var array<class-string<Model>, string>
     */
    public const MODELOS = [
        // Documentos de venta
        SaleInvoice::class => 'Factura de venta',
        CreditDebitNote::class => 'Nota crédito/débito',
        Quotation::class => 'Cotización',
        DeliveryNote::class => 'Remisión',

        // Documentos de compra y gasto
        PurchaseInvoice::class => 'Factura de compra',
        PurchaseReturn::class => 'Devolución de compra',
        Expense::class => 'Gasto',

        // Dinero
        Payment::class => 'Pago',
        CustomerAdvance::class => 'Anticipo de cliente',
        CashRegisterSession::class => 'Turno de caja',
        JournalEntry::class => 'Asiento contable',
        PartnerMovement::class => 'Movimiento de socio',

        // Catálogo y terceros
        Product::class => 'Producto',
        ProductCatalog::class => 'Catálogo público',
        Category::class => 'Categoría',
        ThirdParty::class => 'Tercero',
        Tax::class => 'Impuesto',
        PaymentMethod::class => 'Medio de pago',
        Account::class => 'Cuenta contable',
        Promotion::class => 'Promoción',
        GiftCard::class => 'Bono regalo',

        // Inventario: los ajustes sí, los movimientos automáticos no.
        InventoryAdjustment::class => 'Ajuste de inventario',
        InventoryTransfer::class => 'Traslado de inventario',
        InventoryOpening::class => 'Apertura de inventario',

        // Nómina
        Employee::class => 'Empleado',
        PayrollPeriod::class => 'Período de nómina',
        PayrollSettlement::class => 'Liquidación laboral',

        // Administración
        User::class => 'Usuario',
        Role::class => 'Rol',
        Location::class => 'Sede',
        FiscalPeriod::class => 'Período fiscal',
    ];

    /**
     * Campos que nunca se copian a la bitácora.
     *
     * Dos motivos distintos: unos son secretos que no deben quedar en una tabla
     * que medio administrador puede leer; otros son ruido que cambia solo y
     * llenaría la pantalla de «Modificó» sin que nadie haya hecho nada.
     *
     * @var list<string>
     */
    public const CAMPOS_OCULTOS = [
        // Secretos
        'password', 'remember_token', 'api_token', 'token',
        'dian_token', 'dian_password', 'certificate_password',
        'software_pin', 'software_id', 'technical_key',
        'smtp_password', 'secret',
        // Ruido
        'updated_at', 'created_at', 'deleted_at',
        'remember_token_expires_at', 'last_login_at',
    ];

    /** Un valor largo se recorta: la bitácora no es un respaldo. */
    public const MAX_LARGO_VALOR = 300;

    /** ¿Este modelo se audita? */
    public static function audita(Model|string $modelo): bool
    {
        $clase = $modelo instanceof Model ? $modelo::class : $modelo;

        return array_key_exists($clase, self::MODELOS);
    }

    /** Nombre en español de una clase; el nombre corto si no está registrada. */
    public static function label(?string $clase): string
    {
        if (! $clase) {
            return '';
        }

        return self::MODELOS[$clase] ?? class_basename($clase);
    }

    /**
     * Cómo se identifica un registro concreto para un humano.
     *
     * Se prueba de lo más específico a lo más genérico: el número del documento,
     * el nombre, el código. Si nada aplica queda el id, que al menos permite
     * encontrarlo.
     */
    public static function describir(Model $modelo): string
    {
        if (method_exists($modelo, 'fullNumber')) {
            $numero = (string) $modelo->fullNumber();

            if ($numero !== '') {
                return mb_substr($numero, 0, 200);
            }
        }

        // El código va primero y con el nombre detrás: quien busca en la
        // bitácora suele tener el código a la mano —«PERF-001»— y el nombre
        // solo le sirve para reconocerlo.
        $codigo = $modelo->getAttribute('code');
        $nombre = $modelo->getAttribute('name');

        if (is_string($codigo) && trim($codigo) !== '') {
            $etiqueta = trim($codigo);

            if (is_string($nombre) && trim($nombre) !== '') {
                $etiqueta .= ' — '.trim($nombre);
            }

            return mb_substr($etiqueta, 0, 200);
        }

        foreach (['name', 'full_name', 'number', 'email', 'description'] as $campo) {
            $valor = $modelo->getAttribute($campo);

            if (is_string($valor) && trim($valor) !== '') {
                return mb_substr(trim($valor), 0, 200);
            }
        }

        return '#'.$modelo->getKey();
    }
}
