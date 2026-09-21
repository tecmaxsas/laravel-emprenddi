<?php

/**
 * Fija desde qué número sigue la numeración de notas crédito (o débito) de una
 * empresa, identificándola por el correo de uno de sus usuarios.
 *
 * Por qué hace falta: el consecutivo de notas no se teclea en ninguna pantalla.
 * El motor lo deduce al contabilizar, tomando el mayor entre el "Rango desde" de
 * la resolución, la nota más alta ya emitida con ese prefijo más uno, y el
 * contador de las asignaciones de esa resolución (DocumentNumberer::reserveGlobal).
 * Para saltar hacia adelante hay que mover ese contador, y eso es lo único que
 * hace este script.
 *
 * Uso:
 *   docker compose exec -T app php artisan tinker scripts/consecutivo-notas.php
 *
 * Arranca en modo consulta: informa y no escribe nada. Para aplicar el cambio,
 * pon $APLICAR en true.
 */

use App\Models\Company;
use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\Location;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Sales\DocumentNumberer;
use Illuminate\Support\Facades\DB;

$CORREO = 'impoari@gmail.com';  // cualquier usuario de la empresa
$DESDE = 6;                     // número que llevará la PRÓXIMA nota
$TIPO = 4;                      // 4 = nota crédito, 5 = nota débito (códigos DIAN)
$APLICAR = false;               // true = escribe; false = solo informa

$tipoNombre = Resolution::DOCUMENT_TYPES[$TIPO] ?? 'Documento';
$abortar = function (string $mensaje) {
    echo "\n  ABORTADO: {$mensaje}\n\n";
    exit(1);
};

// El correo puede ser el de un usuario de la empresa o el de un tercero
// registrado como cliente. Se buscan los dos, y si aparece en más de una
// empresa el script se detiene: mover el consecutivo de la empresa equivocada
// no se deshace.
$usuarios = User::withoutGlobalScopes()->where('email', $CORREO)->get();
$terceros = ThirdParty::withoutGlobalScopes()->where('email', $CORREO)->get();

$empresasHalladas = $usuarios->pluck('company_id')
    ->merge($terceros->pluck('company_id'))
    ->filter()
    ->unique()
    ->values();

if ($empresasHalladas->isEmpty()) {
    $abortar("No hay ningún usuario ni tercero con el correo {$CORREO}.");
}

if ($empresasHalladas->count() > 1) {
    $abortar("El correo {$CORREO} aparece en varias empresas (ids ".$empresasHalladas->implode(', ')
        .'). Revísalo antes de seguir.');
}

$empresaId = (int) $empresasHalladas->first();
$empresa = Company::withoutGlobalScopes()->find($empresaId);

echo "Empresa: {$empresa?->name} (id {$empresaId})\n";
echo 'Hallado como: '.($usuarios->isNotEmpty() ? 'usuario' : 'tercero')." — {$CORREO}\n";

// La misma resolución que elegirá el motor al contabilizar la nota: si aquí se
// mirara otra, el contador se movería donde no es.
$resolucion = app(DocumentNumberer::class)
    ->resolucionGlobalDe($empresaId, $TIPO, 'credit_debit_notes');

if (! $resolucion) {
    $abortar("La empresa no tiene una resolución activa de {$tipoNombre}. "
        .'Cárgala en Configuración → DIAN → Resoluciones.');
}

$prefijo = $resolucion->prefix;
$maxUsado = DB::table('credit_debit_notes')
    ->where('company_id', $empresaId)
    ->where('prefix', $prefijo)
    ->max('number');

$asignaciones = LocationResolution::withoutGlobalScopes()
    ->where('dian_resolution_id', $resolucion->id)
    ->get();

$contadorActual = $asignaciones->max('current_consecutive');

$siguienteHoy = max(array_filter([
    (int) $resolucion->range_from,
    $maxUsado !== null ? (int) $maxUsado + 1 : null,
    $contadorActual !== null ? (int) $contadorActual : null,
]));

echo "Resolución: {$resolucion->resolution_number} · prefijo ".($prefijo ?: '(sin prefijo)')
    ." · rango {$resolucion->range_from}–{$resolucion->range_to}\n";
echo 'Nota más alta ya emitida: '.($maxUsado === null ? 'ninguna' : $prefijo.$maxUsado)."\n";
echo 'Contador de asignaciones: '.($contadorActual === null ? 'no hay' : $contadorActual)."\n";
echo "Con la configuración actual, la próxima nota sería la {$prefijo}{$siguienteHoy}\n";
echo "Se quiere que sea la {$prefijo}{$DESDE}\n\n";

if ($DESDE < (int) $resolucion->range_from || $DESDE > (int) $resolucion->range_to) {
    $abortar("El número {$DESDE} está fuera del rango autorizado "
        ."({$resolucion->range_from}–{$resolucion->range_to}).");
}

// Bajar el contador por debajo de lo ya emitido haría que la próxima nota
// repitiera un número que la DIAN ya tiene registrado.
if ($maxUsado !== null && $DESDE <= (int) $maxUsado) {
    $abortar("Ya existe la nota {$prefijo}{$maxUsado}. El consecutivo solo puede subir: "
        .'elige un número mayor.');
}

if ($DESDE === $siguienteHoy) {
    echo "  Nada que hacer: la próxima nota ya sería la {$prefijo}{$DESDE}.\n\n";
    exit(0);
}

if ($DESDE < $siguienteHoy) {
    $abortar("El contador ya va en {$siguienteHoy}. Este script solo lo sube.");
}

if (! $APLICAR) {
    echo "  Modo consulta: no se escribió nada. Pon \$APLICAR = true para aplicarlo.\n\n";
    exit(0);
}

DB::transaction(function () use ($resolucion, $asignaciones, $empresaId, $DESDE, $prefijo, $abortar) {
    if ($asignaciones->isEmpty()) {
        // Las resoluciones de notas no se asignan a ninguna sede —su numeración
        // es de toda la empresa—, así que no existe fila donde llevar el
        // contador. Se crea una, inactiva: lleva la cuenta sin convertirse en la
        // resolución por defecto de esa sede ni aparecer como tal en la UI.
        $sede = Location::withoutGlobalScopes()
            ->where('company_id', $empresaId)
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->first();

        if (! $sede) {
            $abortar('La empresa no tiene ninguna sede donde guardar el contador.');
        }

        LocationResolution::create([
            'location_id' => $sede->id,
            'dian_resolution_id' => $resolucion->id,
            'current_consecutive' => $DESDE,
            'active' => false,
        ]);

        echo "Contador creado en la sede {$sede->name}: próxima nota {$prefijo}{$DESDE}\n";

        return;
    }

    // Si hay varias, todas quedan en el mismo número: el motor toma la mayor.
    foreach ($asignaciones as $asignacion) {
        $asignacion->update(['current_consecutive' => $DESDE]);
    }

    echo "Contador actualizado: próxima nota {$prefijo}{$DESDE}\n";
});

echo "\n  Listo. Verifícalo emitiendo la nota desde la aplicación.\n\n";
