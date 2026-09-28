<?php

/**
 * Consulta el estado de un documento al proveedor y muestra la respuesta tal
 * como llega, sin interpretarla.
 *
 * Sirve cuando la consulta de estado dice «respondió sin datos de estado»: eso
 * significa que el sistema no supo leer la respuesta, no que la DIAN no sepa
 * nada del documento. Para arreglarlo hay que ver la forma exacta que devuelve
 * el proveedor, que cambia entre endpoints y versiones.
 *
 * Solo lee. No cambia el estado de nada.
 *
 * Uso:
 *   docker compose exec -T app php artisan tinker scripts/ver-estado-dian-crudo.php < /dev/null
 */

use App\Models\Company;
use App\Models\Dian\CompanyConfig;
use App\Models\SaleInvoice;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Dian\DianApiClient;

$CORREO = 'impoari@gmail.com';
$NUMERO = 'ARI20';   // prefijo + consecutivo
$CUFE = null;        // opcional: consultar un CUFE suelto en vez del de la factura

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
    ->whereRaw('concat(prefix, number) = ?', [$NUMERO])
    ->first();

$cufe = $CUFE ?: $factura?->cufe;

echo "Empresa: {$empresa?->name} (id {$empresaId})\n";
echo 'Factura: '.($factura ? "{$factura->prefix}{$factura->number} (id {$factura->id})" : 'no encontrada')."\n";
echo 'CUFE a consultar: '.($cufe ?: 'NINGUNO')."\n\n";

if (! $cufe) {
    echo "  ABORTADO: no hay CUFE que consultar.\n\n";
    exit(1);
}

$config = CompanyConfig::query()->where('company_id', $empresaId)->first();

if (! $config || ! $config->api_token) {
    echo "  ABORTADO: la empresa no tiene configurada la facturación electrónica.\n\n";
    exit(1);
}

$resultado = (new DianApiClient($config))->checkDocumentStatus($cufe);

echo 'ok: '.(($resultado['ok'] ?? false) ? 'sí' : 'no')."\n";
echo 'error: '.($resultado['error'] ?? '—')."\n\n";

$data = $resultado['data'] ?? [];

echo "Claves de primer nivel: ".(is_array($data) ? implode(', ', array_keys($data)) : '(no es objeto)')."\n";

$body = $data['ResponseDian']['Envelope']['Body'] ?? null;
echo 'Claves dentro de Body: '.(is_array($body) ? implode(', ', array_keys($body)) : 'no hay Body')."\n\n";

echo "Respuesta completa:\n";
echo mb_substr(
    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
    0,
    6000,
)."\n\n";
