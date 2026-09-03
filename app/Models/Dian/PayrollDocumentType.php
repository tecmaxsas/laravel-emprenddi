<?php

namespace App\Models\Dian;

use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de documento del trabajador en nomina electronica.
 *
 * NO es el mismo catalogo que DocumentType, el de facturacion: los ids
 * difieren aunque el concepto sea el mismo (PEP es 9 aqui y 11 alla).
 *
 * Los ids son los de apidian y viajan en el payload: no se reasignan.
 */
class PayrollDocumentType extends Model
{
    protected $table = 'dian_payroll_document_types';

    protected $fillable = ['code', 'name'];
}
