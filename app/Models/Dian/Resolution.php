<?php

namespace App\Models\Dian;

use App\Models\Company;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resolution extends Model
{
    use BelongsToCompany;

    protected $table = 'dian_resolutions';

    public const KIND_ELECTRONIC = 'electronic';

    public const KIND_POS = 'pos';

    public const KINDS = [
        self::KIND_ELECTRONIC => 'Facturación Electrónica (DIAN)',
        self::KIND_POS => 'POS (sin transmisión a DIAN)',
    ];

    /**
     * Los códigos son **los de la DIAN**, no una numeración nuestra.
     *
     * Esto importa más de lo que parece: `document_type_id` viaja tal cual al
     * proveedor tecnológico al registrar la resolución, y la nota o la factura
     * se transmite con el código DIAN que le corresponde. Si las dos listas no
     * coinciden, la resolución queda registrada allá bajo un tipo distinto del
     * que el documento declara al enviarse.
     *
     * Eso fue exactamente lo que pasó. La lista era una numeración propia
     * (1..6) donde solo el 1 coincidía por casualidad con la DIAN. Las facturas
     * funcionaban; las notas crédito se registraban como tipo 2 —que para la
     * DIAN es Factura de Exportación— y al enviar la nota con su tipo 4 el
     * proveedor no encontraba ninguna resolución y respondía «La resolución no
     * está configurada», con el rango vacío. Nada en ese mensaje apuntaba a un
     * desajuste de catálogos.
     */
    public const DOCUMENT_TYPES = [
        1 => 'Factura Electrónica',
        2 => 'Factura de Exportación',
        4 => 'Nota Crédito',
        5 => 'Nota Débito',
        9 => 'Nómina Electrónica',
        11 => 'Documento Soporte',
    ];

    /**
     * Tipos cuya numeración es de la empresa entera, no de una sede.
     *
     * La DIAN autoriza los rangos de **facturación** por establecimiento: cada
     * punto de venta factura con el suyo, y por eso esas resoluciones se asignan
     * a una sede. Las notas crédito y débito, el documento soporte y la nómina
     * no funcionan así: son un solo consecutivo para toda la empresa, lo emita
     * quien lo emita.
     *
     * Asignar una de estas a una sede no solo sobra: rompe. El motor de notas
     * las buscaba por la sede, no las encontraba, y numeraba la nota por su
     * cuenta —quedaba en NC1, sin resolución—. Al enviarla, el proveedor
     * respondía «La resolución no está configurada» y no había forma de
     * relacionar ese mensaje con una asignación que nadie sabía que hacía falta.
     */
    public const DOCUMENT_TYPES_GLOBALES = [4, 5, 9, 11];

    /** ¿Su numeración es de toda la empresa en vez de por sede? */
    public function isGlobal(): bool
    {
        return in_array((int) $this->document_type_id, self::DOCUMENT_TYPES_GLOBALES, true);
    }

    protected $fillable = [
        'company_id',
        'kind',
        'document_type_id',
        'document_type_name',
        'prefix',
        'resolution_number',
        'resolution_date',
        'technical_key',
        'range_from',
        'range_to',
        'date_from',
        'date_to',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'document_type_id' => 'integer',
            'resolution_date' => 'date',
            'date_from' => 'date',
            'date_to' => 'date',
            'range_from' => 'integer',
            'range_to' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function isPos(): bool
    {
        return $this->kind === self::KIND_POS;
    }

    public function isElectronic(): bool
    {
        return $this->kind === self::KIND_ELECTRONIC;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function locationAssignments(): HasMany
    {
        return $this->hasMany(LocationResolution::class, 'dian_resolution_id');
    }

    public function rangeLabel(): string
    {
        return number_format($this->range_from).' → '.number_format($this->range_to);
    }
}
