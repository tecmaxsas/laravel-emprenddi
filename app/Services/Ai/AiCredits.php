<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiCreditMovement;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * El saldo de Claude de una empresa, **en dólares**.
 *
 * En dólares y no en pesos porque en dólares factura Anthropic y en dólares
 * vende Tecmax: el cliente recarga 50, ve 50, y la tasa de cambio no entra en la
 * cuenta. Guardarlo en pesos hacía que subir la tasa le redujera el poder de
 * compra a un saldo ya recargado.
 *
 * El saldo no es una columna: es el `balance_after` del último movimiento. Así
 * cada centavo cobrado se puede rastrear hasta la conversación que lo consumió,
 * que es lo primero que pregunta un cliente cuando ve que se le acabó.
 *
 * Todo movimiento se escribe con la fila anterior bloqueada, para que dos
 * consumos simultáneos no lean el mismo saldo.
 */
class AiCredits
{
    public function saldo(Company $company): float
    {
        return (float) (AiCreditMovement::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderByDesc('id')
            ->value('balance_after') ?? 0);
    }

    /** Recarga hecha por Tecmax, en dólares. */
    public function recargar(Company $company, float $monto, ?string $nota = null, ?int $usuarioId = null): AiCreditMovement
    {
        return $this->mover($company, AiCreditMovement::TYPE_RECARGA, abs($monto),
            $nota ?: 'Recarga de saldo', null, $usuarioId);
    }

    /** Ajuste manual en dólares, para arriba o para abajo. */
    public function ajustar(Company $company, float $monto, string $nota, ?int $usuarioId = null): AiCreditMovement
    {
        return $this->mover($company, AiCreditMovement::TYPE_AJUSTE, $monto, $nota, null, $usuarioId);
    }

    /**
     * Cobra lo que costó una respuesta.
     *
     * Se permite que el saldo quede en negativo por el último cobro. La
     * alternativa —cortar a mitad de una respuesta ya generada— dejaría al
     * cliente sin lo que ya se pagó a Anthropic. El bloqueo ocurre antes, al
     * empezar la siguiente pregunta.
     */
    public function cobrar(Company $company, float $costo, AiConversation $conversacion): ?AiCreditMovement
    {
        if ($costo <= 0) {
            return null;
        }

        return $this->mover($company, AiCreditMovement::TYPE_CONSUMO, -abs($costo),
            'Conversación: '.$conversacion->title, $conversacion->id);
    }

    private function mover(
        Company $company,
        string $tipo,
        float $monto,
        string $descripcion,
        ?int $conversacionId = null,
        ?int $usuarioId = null,
    ): AiCreditMovement {
        return DB::transaction(function () use ($company, $tipo, $monto, $descripcion, $conversacionId, $usuarioId) {
            $anterior = (float) (DB::table('ai_credit_movements')
                ->where('company_id', $company->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('balance_after') ?? 0);

            return AiCreditMovement::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'type' => $tipo,
                'amount_usd' => round($monto, 6),
                'balance_after' => round($anterior + $monto, 6),
                'description' => mb_substr($descripcion, 0, 250),
                'ai_conversation_id' => $conversacionId,
                'created_by_user_id' => $usuarioId ?? auth()->id(),
                'created_at' => now(),
            ]);
        });
    }
}
