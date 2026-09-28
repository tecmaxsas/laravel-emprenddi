<?php

/**
 * Recupera el CUFE de una factura y consulta a la DIAN cómo quedó de verdad.
 *
 * Existe para las facturas que el proveedor acusó sin devolver la respuesta de
 * la DIAN. Hasta hoy eso se marcaba como rechazo y el CUFE se descartaba, y sin
 * CUFE el botón «Consultar estado DIAN» ni siquiera aparece: la factura quedaba
 * radicada allá y aquí en rojo, sin forma de comprobarlo.
 *
 * Toma el CUFE de la respuesta guardada, o el que se le indique a mano —el que
 * se ve en el panel del proveedor—, y después pregunta por el estado real. No
 * reenvía nada: reenviar duplicaría el documento.
 *
 * Uso:
 *   docker compose exec -T app php artisan tinker scripts/reparar-estado-factura.php < /dev/null
 */

use App\Models\Company;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Dian\DianStatusChecker;

$CORREO = 'impoari@gmail.com';  // usuario o tercero de la empresa
$NUMERO = 'ARI20';              // número completo: prefijo + consecutivo
$CUFE = null;                   // opcional: el CUFE copiado del panel del proveedor
$APLICAR = false;               // true = escribe y consulta; false = solo informa

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

$factura = SaleInvoice::withoutGlobalScopes()
    ->where('company_id', $empresaId)
    ->whereRaw("concat(prefix, number) = ?", [$NUMERO])
    ->first();

if (! $factura) {
    echo "\n  ABORTADO: no hay factura {$NUMERO} en {$empresa?->name}.\n\n";
    exit(1);
}

echo "Empresa: {$empresa?->name} (id {$empresaId})\n";
echo "Factura: id={$factura->id} · {$factura->prefix}{$factura->number} · fecha {$factura->date}\n";
echo 'Estado guardado: '.($factura->dian_status ?: 'sin estado')."\n";
echo 'CUFE guardado: '.($factura->cufe ?: 'ninguno')."\n";
echo 'Mensaje: '.($factura->dian_error_message ?: '(ninguno)')."\n\n";

// De dónde sale el CUFE: el guardado, el de la respuesta del proveedor, o el
// que se pase a mano.
$respuesta = $factura->dian_response;
$cufeEnRespuesta = is_array($respuesta) ? ($respuesta['cufe'] ?? null) : null;
$cufe = $factura->cufe ?: ($CUFE ?: $cufeEnRespuesta);

echo 'CUFE en la respuesta guardada: '.($cufeEnRespuesta ?: 'ninguno')."\n";
echo 'CUFE indicado a mano: '.($CUFE ?: 'ninguno')."\n";
echo 'CUFE a usar: '.($cufe ?: 'NINGUNO')."\n\n";

if (! $cufe) {
    echo "  ABORTADO: no hay CUFE por ningún lado. Cópialo del panel del proveedor y\n"
        ."  ponlo en \$CUFE arriba.\n\n";
    exit(1);
}

if (! $APLICAR) {
    echo "  Modo consulta: no se escribió nada. Pon \$APLICAR = true para guardar el CUFE\n"
        ."  y preguntarle a la DIAN por el estado real.\n\n";
    exit(0);
}

if ($factura->cufe !== $cufe) {
    $factura->update(['cufe' => $cufe]);
    echo "CUFE guardado en la factura.\n";
}

echo "Consultando el estado en la DIAN...\n\n";

$resultado = app(DianStatusChecker::class)->check($factura->fresh());

echo '  ok: '.($resultado['ok'] ? 'sí' : 'no')."\n";
echo '  estado: '.($resultado['status'] ?? '—')."\n";
echo '  código: '.($resultado['status_code'] ?? '—')."\n";
echo '  cambió: '.($resultado['changed'] ? 'sí' : 'no')."\n";
echo '  mensaje: '.$resultado['message']."\n\n";
