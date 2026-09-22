<?php

/**
 * Muestra, palabra por palabra, por qué la DIAN rechazó una nota.
 *
 * La pantalla resume el rechazo en una línea, y hasta hoy ese resumen podía
 * tapar el motivo real: los códigos 90 y 99 se traducían a «Documento ya
 * emitido» sin leer la lista de reglas incumplidas que viene en la misma
 * respuesta. Con un número de nota que no existe en la DIAN, ese mensaje manda
 * a corregir lo que no es.
 *
 * Este script lee la respuesta cruda que quedó guardada en la nota, así que
 * sirve también para los rechazos de antes del arreglo, sin reenviar nada.
 *
 * Uso:
 *   docker compose exec -T app php artisan tinker scripts/ver-rechazo-nota.php < /dev/null
 */

use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Dian\DianErrorReader;

$CORREO = 'impoari@gmail.com';
$NOTA_ID = null;  // null = la última nota de la empresa

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
echo 'Estado DIAN: '.($nota->dian_status ?: 'sin estado')
    .($nota->dian_status_code ? " (código {$nota->dian_status_code})" : '')."\n";
echo 'Resolución: '.($nota->dian_resolution_id ?: 'ninguna')."\n";
echo 'CUFE: '.($nota->cufe ?: 'no')."\n";
echo "\nMensaje guardado en la nota:\n  ".($nota->dian_error_message ?: '(ninguno)')."\n";

$respuesta = $nota->dian_response;

if (! is_array($respuesta) || $respuesta === []) {
    echo "\n  No hay respuesta guardada: la nota no se ha enviado, o el envío ni siquiera "
        ."llegó al proveedor.\n\n";
    exit(0);
}

$resultado = $respuesta['ResponseDian']['Envelope']['Body']['SendBillSyncResponse']['SendBillSyncResult'] ?? null;

if (! is_array($resultado)) {
    // Sin bloque de DIAN, el rechazo vino del proveedor: sus propias
    // validaciones, antes de llegar a la DIAN.
    echo "\nLa respuesta no trae bloque de la DIAN. Lo que respondió el proveedor:\n";
    echo '  '.mb_substr(json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), 0, 4000)."\n\n";
    exit(0);
}

echo "\nRespuesta de la DIAN:\n";
echo '  StatusCode: '.DianErrorReader::texto($resultado['StatusCode'] ?? null)."\n";
echo '  StatusDescription: '.DianErrorReader::texto($resultado['StatusDescription'] ?? null)."\n";
echo '  IsValid: '.DianErrorReader::texto($resultado['IsValid'] ?? null)."\n";

$reglas = DianErrorReader::reglas($resultado);

echo "\nReglas que la DIAN reporta incumplidas:\n";

if ($reglas === []) {
    echo "  (ninguna: la DIAN no detalló nada)\n";
} else {
    foreach ($reglas as $i => $regla) {
        echo '  '.($i + 1).'. '.$regla."\n";
    }
}

echo "\n";
