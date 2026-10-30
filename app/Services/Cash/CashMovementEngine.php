<?php

namespace App\Services\Cash;

use App\Models\CashMovement;
use App\Models\CashRegisterSession;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ThirdParty;
use App\Services\Accounting\JournalEntryNumberer;
use App\Support\DefaultAccounts;
use App\Support\PaymentAccountResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registrar y anular entradas y salidas directas de la caja.
 *
 * Lo que este archivo protege son tres cosas, y conviene saber por qué cada
 * una está:
 *
 *  1. **No se saca más de lo que hay.** El cajón no puede quedar en negativo.
 *     Si el sistema lo permite, lo que aparece al cerrar no es un error de
 *     digitación: es una caja que nunca tuvo esa plata, y descubrirlo al
 *     final del turno ya no se puede reconstruir.
 *
 *  2. **Siempre hay asiento.** Si el efectivo baja y los libros no lo
 *     explican, la contabilidad empieza a mentir en silencio.
 *
 *  3. **No se borra, se anula.** Con motivo y reversando el asiento. Un
 *     movimiento de caja que desaparece es exactamente la forma de sacar
 *     plata sin dejar rastro.
 */
class CashMovementEngine
{
    public function __construct(
        protected CashSessionSummary $resumen,
        protected JournalEntryNumberer $numerador,
    ) {}

    /**
     * Registra el movimiento y lo contabiliza.
     *
     * @param  array{direction: string, reason: string, concept: string, amount: float|string,
     *               third_party_id?: int|null, counterpart_account_id?: int|null,
     *               notes?: string|null}  $datos
     */
    public function registrar(CashRegisterSession $sesion, array $datos): CashMovement
    {
        if ($sesion->status !== CashRegisterSession::STATUS_OPEN) {
            throw new RuntimeException(
                'El turno de caja ya está cerrado. Un movimiento de un turno cerrado cambiaría un '
                .'arqueo que alguien ya firmó.'
            );
        }

        $direccion = $datos['direction'] ?? '';

        if (! in_array($direccion, [CashMovement::IN, CashMovement::OUT], true)) {
            throw new RuntimeException('Indica si es una entrada o una salida de caja.');
        }

        $motivo = $datos['reason'] ?? '';

        if (! CashMovement::esMotivoValido($motivo, $direccion)) {
            throw new RuntimeException('El motivo no corresponde a una '.strtolower(CashMovement::DIRECTIONS[$direccion]).'.');
        }

        $concepto = trim((string) ($datos['concept'] ?? ''));

        if ($concepto === '') {
            throw new RuntimeException(
                'Escribe para qué fue. Es lo único que va a explicar esa plata cuando alguien revise '
                .'el turno dentro de tres meses.'
            );
        }

        $monto = round((float) ($datos['amount'] ?? 0), 2);

        if ($monto <= 0) {
            throw new RuntimeException('El valor del movimiento tiene que ser mayor que cero.');
        }

        if ($direccion === CashMovement::OUT) {
            $this->exigirQueAlcance($sesion, $monto);
        }

        $tercero = $this->resolverTercero($sesion, $datos['third_party_id'] ?? null);

        $cuentaCaja = PaymentAccountResolver::forMethod('cash');

        $contrapartida = $datos['counterpart_account_id']
            ?? DefaultAccounts::contrapartidaDeMovimientoDeCaja($motivo, (int) $sesion->company_id);

        return DB::transaction(function () use (
            $sesion, $direccion, $motivo, $concepto, $monto, $tercero, $cuentaCaja, $contrapartida, $datos,
        ) {
            $movimiento = CashMovement::create([
                'company_id' => $sesion->company_id,
                'cash_register_session_id' => $sesion->id,
                'location_id' => $sesion->location_id,
                'direction' => $direccion,
                'reason' => $motivo,
                'concept' => $concepto,
                'amount' => $monto,
                'cash_account_id' => $cuentaCaja,
                'counterpart_account_id' => $contrapartida,
                'third_party_id' => $tercero?->id,
                'notes' => $datos['notes'] ?? null,
                'created_by_user_id' => Auth::id(),
            ]);

            $asiento = $this->contabilizar($movimiento);

            if ($asiento) {
                $movimiento->forceFill(['journal_entry_id' => $asiento->id])->save();
            }

            return $movimiento->fresh();
        });
    }

    /**
     * Anula un movimiento y devuelve la plata a donde estaba.
     *
     * Solo mientras el turno siga abierto: después de cerrar, el arqueo ya se
     * firmó y cambiarlo por detrás convertiría el cierre en un documento que
     * no significa nada. Lo que corresponde entonces es un ajuste del turno
     * siguiente, con su propio rastro.
     */
    public function anular(CashMovement $movimiento, string $motivo): CashMovement
    {
        if ($movimiento->estaAnulado()) {
            throw new RuntimeException('Este movimiento ya estaba anulado.');
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException(
                'La anulación necesita motivo escrito: es plata que entró o salió del cajón y alguien '
                .'tiene que poder responder por qué se deshizo.'
            );
        }

        $sesion = $movimiento->session;

        if (! $sesion || $sesion->status !== CashRegisterSession::STATUS_OPEN) {
            throw new RuntimeException(
                'El turno ya está cerrado. Para corregir esto hay que registrar el movimiento contrario '
                .'en el turno actual, que es lo que deja el rastro completo.'
            );
        }

        // Anular una entrada saca plata del cajón: hay que comprobar que
        // todavía esté ahí, igual que en una salida normal.
        if ($movimiento->esEntrada()) {
            $this->exigirQueAlcance($sesion, (float) $movimiento->amount, $movimiento->id);
        }

        return DB::transaction(function () use ($movimiento, $motivo) {
            $this->reversar($movimiento, $motivo);

            $movimiento->update([
                'voided_at' => now(),
                'void_reason' => $motivo,
                'voided_by_user_id' => Auth::id(),
            ]);

            return $movimiento->fresh();
        });
    }

    /**
     * Lo que hay en el cajón ahora mismo.
     *
     * Sale de `CashSessionSummary`, que es el mismo que calcula el esperado al
     * cerrar. Si este archivo lo contara por su cuenta, un día el POS dejaría
     * sacar plata que el arqueo no tiene.
     */
    public function efectivoDisponible(CashRegisterSession $sesion): float
    {
        return (float) $this->resumen->compute($sesion)['expected_cash'];
    }

    /**
     * Revienta si la salida deja el cajón en negativo.
     *
     * @param  int|null  $ignorarMovimiento  Para la anulación: el movimiento
     *                                       que se está deshaciendo todavía
     *                                       cuenta en el resumen.
     */
    protected function exigirQueAlcance(CashRegisterSession $sesion, float $monto, ?int $ignorarMovimiento = null): void
    {
        $disponible = $this->efectivoDisponible($sesion);

        if ($ignorarMovimiento) {
            $movimiento = CashMovement::find($ignorarMovimiento);

            // Al anular una entrada, esa plata ya está sumada en el
            // disponible y hay que descontarla para saber si de verdad
            // alcanza sin ella.
            if ($movimiento && $movimiento->esEntrada()) {
                $disponible -= (float) $movimiento->amount;
            }
        }

        if ($monto > $disponible + 0.01) {
            throw new RuntimeException(sprintf(
                'En la caja hay $%s y se están sacando $%s. Revisa el valor, o registra primero la '
                .'entrada que falta.',
                number_format(max(0, $disponible), 0, ',', '.'),
                number_format($monto, 0, ',', '.'),
            ));
        }
    }

    /**
     * El asiento: la caja contra la contrapartida del motivo.
     *
     *   Entrada  →  DR caja    CR contrapartida
     *   Salida   →  DR contrapartida    CR caja
     *
     * Sin alguna de las dos cuentas no se contabiliza, pero el movimiento se
     * guarda igual: el arqueo tiene que cuadrar aunque el PUC esté incompleto.
     * Queda con `journal_entry_id` en null, que es la señal de que el contador
     * tiene algo que imputar.
     */
    protected function contabilizar(CashMovement $movimiento): ?JournalEntry
    {
        if (! $movimiento->cash_account_id || ! $movimiento->counterpart_account_id) {
            return null;
        }

        $empresa = $movimiento->company;
        $monto = (float) $movimiento->amount;
        $esEntrada = $movimiento->esEntrada();

        $asiento = JournalEntry::create([
            'company_id' => $movimiento->company_id,
            'prefix' => 'MC',
            'number' => $this->numerador->next($empresa, 'MC'),
            'date' => now()->toDateString(),
            'type' => 'payment',
            'reference' => 'MC-'.str_pad((string) $movimiento->id, 6, '0', STR_PAD_LEFT),
            'third_party_id' => $movimiento->third_party_id,
            'description' => $movimiento->etiquetaDelMotivo().' — '.$movimiento->concept,
            'status' => JournalEntry::STATUS_POSTED,
            'total_debit' => $monto,
            'total_credit' => $monto,
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
        ]);

        $this->linea($asiento, 1, $esEntrada ? $movimiento->cash_account_id : $movimiento->counterpart_account_id,
            $monto, 0, $movimiento);

        $this->linea($asiento, 2, $esEntrada ? $movimiento->counterpart_account_id : $movimiento->cash_account_id,
            0, $monto, $movimiento);

        return $asiento;
    }

    /** El asiento contrario, para la anulación. */
    protected function reversar(CashMovement $movimiento, string $motivo): ?JournalEntry
    {
        if (! $movimiento->journal_entry_id) {
            return null;
        }

        $empresa = $movimiento->company;
        $monto = (float) $movimiento->amount;
        $esEntrada = $movimiento->esEntrada();

        $asiento = JournalEntry::create([
            'company_id' => $movimiento->company_id,
            'prefix' => 'MC',
            'number' => $this->numerador->next($empresa, 'MC'),
            'date' => now()->toDateString(),
            'type' => 'payment',
            'reference' => 'ANUL MC-'.str_pad((string) $movimiento->id, 6, '0', STR_PAD_LEFT),
            'third_party_id' => $movimiento->third_party_id,
            'description' => 'Anulación: '.$movimiento->concept.' — '.$motivo,
            'status' => JournalEntry::STATUS_POSTED,
            'total_debit' => $monto,
            'total_credit' => $monto,
            'posted_at' => now(),
            'posted_by_user_id' => Auth::id(),
            'created_by_user_id' => Auth::id(),
        ]);

        // Al revés que el original.
        $this->linea($asiento, 1, $esEntrada ? $movimiento->counterpart_account_id : $movimiento->cash_account_id,
            $monto, 0, $movimiento);

        $this->linea($asiento, 2, $esEntrada ? $movimiento->cash_account_id : $movimiento->counterpart_account_id,
            0, $monto, $movimiento);

        return $asiento;
    }

    protected function linea(
        JournalEntry $asiento,
        int $numero,
        ?int $cuenta,
        float $debito,
        float $credito,
        CashMovement $movimiento,
    ): void {
        JournalEntryLine::create([
            'journal_entry_id' => $asiento->id,
            'line_number' => $numero,
            'account_id' => $cuenta,
            'third_party_id' => $movimiento->third_party_id,
            'debit' => $debito,
            'credit' => $credito,
            'description' => $movimiento->concept,
        ]);
    }

    protected function resolverTercero(CashRegisterSession $sesion, ?int $id): ?ThirdParty
    {
        if (! $id) {
            return null;
        }

        return ThirdParty::query()
            ->where('company_id', $sesion->company_id)
            ->find($id);
    }
}
