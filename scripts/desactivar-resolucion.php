<?php

/**
 * Desactiva una resolución cargada por equivocación.
 *
 * La pantalla de Configuración → DIAN deja cargar resoluciones pero no
 * retirarlas, y una resolución de más no es inofensiva: cuando hay dos activas
 * del mismo tipo, el motor prefiere la más reciente que tenga cupo, así que las
 * notas siguientes salen con el prefijo equivocado sin que nadie lo haya pedido.
 *
 * Desactivar no borra nada: la resolución queda registrada con sus datos y su
 * historia, solo deja de ser candidata para numerar documentos nuevos. Los que
 * ya se emitieron con ella conservan su número y su referencia.
 *
 * Uso:
 *   docker compose exec -T app php artisan tinker scripts/desactivar-resolucion.php
 */

use App\Models\Company;
use App\Models\Dian\Resolution;
use Illuminate\Support\Facades\DB;

$RESOLUCION_ID = 28;   // id que sale en scripts/estado-notas.php
$APLICAR = false;      // true = escribe; false = solo informa

$resolucion = Resolution::withoutGlobalScopes()->find($RESOLUCION_ID);

if (! $resolucion) {
    echo "\n  ABORTADO: no existe la resolución {$RESOLUCION_ID}.\n\n";
    exit(1);
}

$empresa = Company::withoutGlobalScopes()->find($resolucion->company_id);

echo sprintf(
    "Resolución %d — %s · prefijo %s · rango %d-%d · %s\n",
    $resolucion->id,
    Resolution::DOCUMENT_TYPES[$resolucion->document_type_id] ?? '?',
    $resolucion->prefix ?: '(sin prefijo)',
    $resolucion->range_from,
    $resolucion->range_to,
    $resolucion->active ? 'activa' : 'ya está inactiva',
);
echo "Empresa: {$empresa?->name} (id {$resolucion->company_id})\n";

// Documentos ya emitidos con ella. No impide desactivarla —una resolución
// agotada se retira igual—, pero cambia lo que significa hacerlo.
$notas = DB::table('credit_debit_notes')
    ->where('company_id', $resolucion->company_id)
    ->where('dian_resolution_id', $resolucion->id)
    ->count();

$facturas = DB::table('sale_invoices')
    ->where('company_id', $resolucion->company_id)
    ->where('prefix', $resolucion->prefix)
    ->count();

echo "Documentos emitidos con ella: {$notas} notas, {$facturas} facturas con ese prefijo\n";

// Con cuál quedaría la empresa después, que es lo que de verdad importa.
$quedan = Resolution::withoutGlobalScopes()
    ->where('company_id', $resolucion->company_id)
    ->where('document_type_id', $resolucion->document_type_id)
    ->where('active', true)
    ->where('id', '!=', $resolucion->id)
    ->get();

echo "\nDespués de desactivarla, quedan activas de ese tipo:\n";

if ($quedan->isEmpty()) {
    echo "  NINGUNA — la empresa se quedaría sin poder numerar ese documento.\n";
} else {
    foreach ($quedan as $r) {
        echo "  id={$r->id} · prefijo ".($r->prefix ?: '(sin prefijo)')
            ." · rango {$r->range_from}-{$r->range_to}\n";
    }
}

if (! $resolucion->active) {
    echo "\n  Nada que hacer: ya estaba inactiva.\n\n";
    exit(0);
}

if (! $APLICAR) {
    echo "\n  Modo consulta: no se escribió nada. Pon \$APLICAR = true para aplicarlo.\n\n";
    exit(0);
}

$resolucion->update(['active' => false]);

echo "\n  Listo: la resolución {$resolucion->id} ({$resolucion->prefix}) queda inactiva.\n\n";
