<?php

/**
 * Muestra en qué estado quedaron las notas crédito y débito de una empresa,
 * identificada por el correo de uno de sus usuarios o terceros.
 *
 * Solo lee. Sirve para decidir qué hacer con una nota que la DIAN rechazó:
 * si todavía no tiene resolución, se puede renumerar desde la pantalla de la
 * nota con el botón «Asignar resolución DIAN»; si ya la tiene, hace falta otra
 * cosa, y lo que hace falta depende de por qué la rechazaron.
 *
 * Uso:
 *   docker compose exec -T app php artisan tinker scripts/estado-notas.php
 */

use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\Dian\Resolution;
use App\Models\ThirdParty;
use App\Models\User;

$CORREO = 'impoari@gmail.com';
$CUANTAS = 15;  // últimas notas a listar

$usuarios = User::withoutGlobalScopes()->where('email', $CORREO)->get();
$terceros = ThirdParty::withoutGlobalScopes()->where('email', $CORREO)->get();

$empresas = $usuarios->pluck('company_id')
    ->merge($terceros->pluck('company_id'))
    ->filter()
    ->unique()
    ->values();

if ($empresas->count() !== 1) {
    echo "\n  ABORTADO: el correo {$CORREO} no identifica una sola empresa (ids: "
        .($empresas->isEmpty() ? 'ninguna' : $empresas->implode(', ')).")\n\n";
    exit(1);
}

$empresaId = (int) $empresas->first();
$empresa = Company::withoutGlobalScopes()->find($empresaId);

echo "Empresa: {$empresa?->name} (id {$empresaId})\n\n";

echo "Resoluciones de notas:\n";
$resoluciones = Resolution::withoutGlobalScopes()
    ->where('company_id', $empresaId)
    ->whereIn('document_type_id', [4, 5])
    ->get();

if ($resoluciones->isEmpty()) {
    echo "  (ninguna)\n";
}

foreach ($resoluciones as $r) {
    echo sprintf(
        "  id=%d · %s · prefijo %s · rango %d-%d · %s\n",
        $r->id,
        Resolution::DOCUMENT_TYPES[$r->document_type_id] ?? '?',
        $r->prefix ?: '(sin prefijo)',
        $r->range_from,
        $r->range_to,
        $r->active ? 'activa' : 'INACTIVA',
    );
}

echo "\nÚltimas notas:\n";
$notas = CreditDebitNote::withoutGlobalScopes()
    ->where('company_id', $empresaId)
    ->orderByDesc('id')
    ->limit($CUANTAS)
    ->get();

if ($notas->isEmpty()) {
    echo "  (ninguna)\n";
}

foreach ($notas as $n) {
    echo sprintf(
        "\n  id=%d  %s%s  %s  tipo=%s  fecha=%s  total=%s\n",
        $n->id,
        $n->prefix,
        $n->number,
        CreditDebitNote::STATUSES[$n->status] ?? $n->status,
        $n->type,
        $n->date,
        number_format((float) $n->total, 2),
    );
    echo sprintf(
        "      DIAN: %s%s · resolución: %s · CUFE: %s\n",
        CreditDebitNote::DIAN_STATUSES[$n->dian_status] ?? ($n->dian_status ?: 'sin estado'),
        $n->dian_status_code ? " (código {$n->dian_status_code})" : '',
        $n->dian_resolution_id ?: 'NINGUNA',
        $n->cufe ? 'sí' : 'no',
    );

    if ($n->dian_error_message) {
        echo '      Motivo del rechazo: '.mb_substr((string) $n->dian_error_message, 0, 300)."\n";
    }

    // Las mismas condiciones del botón de la pantalla de la nota.
    $puedeRenumerar = $n->status === CreditDebitNote::STATUS_POSTED
        && ! $n->dian_resolution_id
        && $n->dian_status !== CreditDebitNote::DIAN_ACCEPTED;

    echo '      Botón «Asignar resolución DIAN»: '.($puedeRenumerar ? 'DISPONIBLE' : 'no aparece')."\n";
}

echo "\n";
