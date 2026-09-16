<?php

namespace App\Services\Reports;

use App\Models\SaleInvoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Cartera por edades: un renglón por cliente, con el saldo repartido según
 * cuánto lleva vencido.
 *
 * El detalle factura por factura ya existe en «Cartera (CxC)». Esto es la otra
 * mitad de la pregunta: **a quién hay que cobrarle y qué tan tarde va**. Un
 * cliente con diez facturas pequeñas al día no es el mismo problema que uno con
 * una sola de hace cuatro meses, y en el listado plano los dos se ven igual.
 *
 * Vive aparte de la pantalla a propósito: la vista y la exportación tienen que
 * dar exactamente el mismo número. Calcularlo en cada una es cómo terminan
 * discrepando, y un reporte de cartera que no cuadra consigo mismo no lo usa
 * nadie.
 *
 * **El saldo es `net_payable - paid_amount`**, no `total`. Las retenciones que
 * el cliente practica nunca fueron plata por cobrar: incluirlas infla la cartera
 * con dinero que no va a entrar nunca.
 */
class AccountsReceivableAging
{
    /**
     * Los tramos, en días vencidos. El último es abierto.
     *
     * Son los del uso corriente en Colombia. El corriente («por vencer») va
     * aparte porque no es mora: es cartera sana y mezclarla con lo vencido
     * esconde justamente lo que el reporte viene a mostrar.
     *
     * @var list<array{clave: string, rotulo: string, desde: int, hasta: int|null}>
     */
    public const TRAMOS = [
        ['clave' => 'corriente', 'rotulo' => 'Por vencer', 'desde' => 0, 'hasta' => 0],
        ['clave' => 'd1_30', 'rotulo' => '1 – 30', 'desde' => 1, 'hasta' => 30],
        ['clave' => 'd31_60', 'rotulo' => '31 – 60', 'desde' => 31, 'hasta' => 60],
        ['clave' => 'd61_90', 'rotulo' => '61 – 90', 'desde' => 61, 'hasta' => 90],
        ['clave' => 'd90_mas', 'rotulo' => 'Más de 90', 'desde' => 91, 'hasta' => null],
    ];

    /**
     * @param  string  $corte  Fecha de corte (Y-m-d).
     * @param  bool  $soloConSaldo  Oculta los clientes que quedaron en cero.
     * @return Collection<int, array<string, mixed>>
     */
    public function porTercero(
        int $companyId,
        string $corte,
        bool $soloConSaldo = true,
        ?int $thirdPartyId = null,
    ): Collection {
        $fechaCorte = Carbon::parse($corte)->startOfDay();

        $facturas = SaleInvoice::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', SaleInvoice::STATUS_POSTED)
            ->whereDate('date', '<=', $fechaCorte->toDateString())
            ->whereIn('payment_status', [
                SaleInvoice::PAYMENT_PENDIENTE,
                SaleInvoice::PAYMENT_PARCIAL,
                SaleInvoice::PAYMENT_VENCIDO,
            ])
            ->when($thirdPartyId, fn ($q) => $q->where('third_party_id', $thirdPartyId))
            ->with(['customer:id,name,document_number,phone,email'])
            ->get();

        $porCliente = [];

        foreach ($facturas as $factura) {
            $saldo = round((float) $factura->balance, 2);

            // Un saldo en cero o negativo no es cartera. El negativo puede pasar
            // con un anticipo mal aplicado y sumarlo restaría de la mora de otra
            // factura, que es justo lo contrario de lo que el reporte debe decir.
            if ($saldo <= 0.009) {
                continue;
            }

            $clienteId = (int) $factura->third_party_id;

            $porCliente[$clienteId] ??= $this->filaVacia($factura);

            $tramo = $this->tramoDe($factura, $fechaCorte);

            $porCliente[$clienteId][$tramo] += $saldo;
            $porCliente[$clienteId]['total'] += $saldo;
            $porCliente[$clienteId]['facturas']++;

            $dias = $this->diasVencidos($factura, $fechaCorte);

            if ($dias > $porCliente[$clienteId]['dias_max']) {
                $porCliente[$clienteId]['dias_max'] = $dias;
            }

            // La más próxima a vencer de las que aún no vencen: es la fecha que
            // le sirve a quien llama a cobrar.
            $vence = $factura->due_date?->toDateString();

            if ($vence && ($porCliente[$clienteId]['vence_proxima'] === null
                || $vence < $porCliente[$clienteId]['vence_proxima'])) {
                $porCliente[$clienteId]['vence_proxima'] = $vence;
            }
        }

        $filas = collect($porCliente)->values();

        if ($soloConSaldo) {
            $filas = $filas->filter(fn (array $f) => $f['total'] > 0.009)->values();
        }

        // De mayor a menor mora: el reporte se lee de arriba hacia abajo y
        // arriba tiene que estar lo que más urge.
        return $filas->sortByDesc('dias_max')->sortByDesc(fn (array $f) => $f['d90_mas'])->values();
    }

    /**
     * Los totales de cada tramo, para el pie del reporte.
     *
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array<string, float>
     */
    public function totales(Collection $filas): array
    {
        $totales = ['total' => 0.0];

        foreach (self::TRAMOS as $tramo) {
            $totales[$tramo['clave']] = 0.0;
        }

        foreach ($filas as $fila) {
            foreach (self::TRAMOS as $tramo) {
                $totales[$tramo['clave']] += (float) $fila[$tramo['clave']];
            }

            $totales['total'] += (float) $fila['total'];
        }

        return $totales;
    }

    /** @return array<string, mixed> */
    private function filaVacia(SaleInvoice $factura): array
    {
        $fila = [
            'third_party_id' => (int) $factura->third_party_id,
            'documento' => $factura->customer?->document_number ?: '',
            'nombre' => $factura->customer?->name ?: 'Sin cliente',
            'telefono' => $factura->customer?->phone ?: '',
            'correo' => $factura->customer?->email ?: '',
            'facturas' => 0,
            'dias_max' => 0,
            'vence_proxima' => null,
            'total' => 0.0,
        ];

        foreach (self::TRAMOS as $tramo) {
            $fila[$tramo['clave']] = 0.0;
        }

        return $fila;
    }

    /**
     * Cuántos días lleva vencida a la fecha de corte. Cero si aún no vence.
     *
     * Una factura sin fecha de vencimiento se trata como corriente y no como
     * vencida: no tener plazo pactado no es estar en mora, y contarla como
     * vencida inflaría el tramo más grave del reporte con facturas que nadie
     * puede reclamar todavía.
     */
    private function diasVencidos(SaleInvoice $factura, Carbon $corte): int
    {
        if (! $factura->due_date) {
            return 0;
        }

        $dias = (int) $factura->due_date->copy()->startOfDay()->diffInDays($corte, false);

        return max(0, $dias);
    }

    private function tramoDe(SaleInvoice $factura, Carbon $corte): string
    {
        $dias = $this->diasVencidos($factura, $corte);

        foreach (self::TRAMOS as $tramo) {
            if ($dias >= $tramo['desde'] && ($tramo['hasta'] === null || $dias <= $tramo['hasta'])) {
                return $tramo['clave'];
            }
        }

        return 'd90_mas';
    }
}
