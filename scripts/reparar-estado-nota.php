<?php

/**
 * Corrige el estado de una nota que la DIAN aceptó y el sistema marcó como
 * rechazada, leyendo la respuesta que ya quedó guardada.
 *
 * Pasaba cuando el proveedor devolvía el identificador como `cude` —su nombre
 * en las notas— y el sistema solo miraba `cufe`: sin código no había
 * aceptación, aunque la DIAN hubiera respondido «Procesado Correctamente».
 * Reenviar no sirve y hace daño: el documento ya está radicado, y el segundo
 * envío vuelve como «documento ya emitido».
 *
 * No habla con la DIAN ni con el proveedor. Solo relee lo guardado.
 *
 * Uso:
 *   docker compose exec -T app php artisan tinker scripts/reparar-estado-nota.php < /dev/null
 */

use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Dian\DianErrorReader;

$CORREO = 'impoari@gmail.com';
$NOTA_ID = null;   // null = la última nota de la empresa
$APLICAR = false;  // true = escribe; false = solo informa

$usuarios = User::withoutGlobalScopes()->where('email', $CORREO)->get();
$terceros = ThirdParty::withoutGlobalScopes()->where('email', $CORREO)->get();

$empresas = $usuarios->pluck('company_id')
    ->merge($terceros->pluck('company_id'))
    ->filter()
    ->unique()
    ->values();

if ($empresas->count() !== 1) {
    echo "\n  ABORTADO: el correo {$CORREO} no identifica una sola empresa.\n\n";
    exit(1);
}

$empresaId = (int) $empresas->first();
$empresa = Company::withoutGlobalScopes()->find($empresaId);

$nota = CreditDebitNote::withoutGlobalScopes()
    ->where('company_id', $empresaId)
    ->when($NOTA_ID, fn ($q) => $q->whereKey($NOTA_ID))
    ->orderByDesc('id')
    ->first();

if (! $nota) {
    echo "\n  ABORTADO: la empresa {$empresa?->name} no tiene notas.\n\n";
    exit(1);
}

echo "Empresa: {$empresa?->name} (id {$empresaId})\n";
echo "Nota: id={$nota->id} · {$nota->prefix}{$nota->number} · fecha {$nota->date}\n";
echo 'Estado guardado: '.($nota->dian_status ?: 'sin estado')
    .($nota->dian_status_code ? " (código {$nota->dian_status_code})" : '')."\n";
echo 'CUDE guardado: '.($nota->cufe ?: 'ninguno')."\n\n";

$respuesta = $nota->dian_response;

if (! is_array($respuesta) || $respuesta === []) {
    echo "  ABORTADO: la nota no tiene respuesta guardada. No hay nada que releer.\n\n";
    exit(1);
}

$resultado = $respuesta['ResponseDian']['Envelope']['Body']['SendBillSyncResponse']['SendBillSyncResult'] ?? null;

if (! is_array($resultado)) {
    echo "  ABORTADO: la respuesta guardada no trae el bloque de la DIAN, así que no "
        ."dice si el documento fue aceptado.\n\n";
    exit(1);
}

$statusCode = DianErrorReader::texto($resultado['StatusCode'] ?? null);
$isValid = filter_var($resultado['IsValid'] ?? false, FILTER_VALIDATE_BOOLEAN);
$codigo = DianErrorReader::texto($respuesta['cude'] ?? $respuesta['cufe'] ?? null) ?: null;
$avisos = DianErrorReader::reglas($resultado);

echo "Lo que dice la respuesta guardada:\n";
echo '  StatusCode: '.$statusCode.' — '.DianErrorReader::texto($resultado['StatusDescription'] ?? null)."\n";
echo '  IsValid: '.($isValid ? 'true' : 'false')."\n";
echo '  CUDE en la respuesta: '.($codigo ?: 'NINGUNO')."\n";
echo '  Avisos: '.($avisos === [] ? 'ninguno' : implode(' · ', $avisos))."\n\n";

if (! $isValid && $statusCode !== '00') {
    echo "  Nada que reparar: la DIAN no aceptó este documento. El rechazo es real.\n\n";
    exit(0);
}

if ($nota->isDianAccepted() && $nota->cufe === $codigo) {
    echo "  Nada que reparar: la nota ya figura aceptada con ese CUDE.\n\n";
    exit(0);
}

echo "La DIAN sí aceptó este documento. Quedaría:\n";
echo "  Estado: aceptado (código {$statusCode})\n";
echo '  CUDE: '.($codigo ?: 'sin CUDE — habrá que reclamárselo al proveedor')."\n\n";

if (! $APLICAR) {
    echo "  Modo consulta: no se escribió nada. Pon \$APLICAR = true para aplicarlo.\n\n";
    exit(0);
}

$nota->update([
    'dian_status' => CreditDebitNote::DIAN_ACCEPTED,
    'dian_status_code' => $statusCode,
    'cufe' => $codigo,
    'qr_url' => $codigo
        ? 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$codigo
        : null,
    'dian_error_message' => $codigo
        ? ($avisos === [] ? null : implode(' · ', $avisos))
        : 'La DIAN la aceptó, pero el proveedor no devolvió el CUDE, así que no hay PDF ni QR. '
            .'Reclámalo al proveedor: el documento ya está radicado y volver a enviarlo lo duplicaría.',
]);

echo "  Listo: la nota {$nota->prefix}{$nota->number} queda como aceptada por la DIAN.\n\n";
