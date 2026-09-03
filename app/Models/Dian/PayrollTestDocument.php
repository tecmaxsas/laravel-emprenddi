<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

/**
 * Un documento del set de pruebas de nomina.
 *
 * Lleva la cuenta de que se envio y con que resultado, para poder retomar la
 * habilitacion donde se quedo en vez de empezar de cero.
 */
class PayrollTestDocument extends Model
{
    public const KIND_NOMINA = 'nomina';

    public const KIND_NOTA = 'nota';

    public const PENDIENTE = 'pendiente';

    /** Recibido por la DIAN. El veredicto llega despues, por la via asincrona. */
    public const ENVIADO = 'enviado';

    public const ERROR = 'error';

    protected $table = 'dian_payroll_test_documents';

    protected $fillable = [
        'company_id',
        'kind',
        'slot',
        'prefix',
        'consecutive',
        'cune',
        'zip_key',
        'issue_date',
        'status',
        'error_message',
        'payload',
        'response',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'consecutive' => 'integer',
            'issue_date' => 'date',
            'payload' => 'array',
            'response' => 'array',
        ];
    }

    /**
     * Una nota de ajuste solo se puede mandar si su nomina tiene CUNE: la DIAN
     * responde NIAE191a ("Documento a Reemplazar no encuentra recibido en la
     * Base de Datos") cuando la predecesora no le consta.
     */
    public function puedeSerReemplazada(): bool
    {
        return $this->status === self::ENVIADO && filled($this->cune);
    }
}
