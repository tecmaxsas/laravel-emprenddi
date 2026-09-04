<x-filament-panels::page>

    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @if ($statement)
        @php($s = $statement)

        {{-- Resumen: lo primero que alguien quiere saber es cuánto debe --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @php($tarjetas = [
                ['Total facturado', $s['invoiced'], 'text-gray-900 dark:text-gray-100'],
                ['Total abonado', $s['paid'], 'text-green-600 dark:text-green-400'],
                ['Saldo a favor', $s['advance_balance'], 'text-blue-600 dark:text-blue-400'],
                [$s['due'] >= 0 ? 'Saldo adeudado' : 'Saldo a favor', abs($s['due']),
                    $s['due'] > 0.01 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'],
            ])

            @foreach ($tarjetas as [$titulo, $valor, $color])
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $titulo }}
                    </div>
                    <div class="mt-1 text-xl font-bold tabular-nums {{ $color }}">
                        ${{ number_format($valor, 0, ',', '.') }}
                    </div>
                </div>
            @endforeach
        </div>

        @if ($s['advance_balance'] > 0.01 && $s['due'] > 0.01)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                Este cliente tiene saldo a favor <strong>y</strong> saldo adeudado al mismo tiempo. No debería
                pasar: el anticipo se aplica solo a las facturas pendientes. Revisa si alguna factura quedó
                sin contabilizar, porque un anticipo no se aplica sobre borradores.
            </div>
        @endif

        <div class="flex flex-wrap gap-2">
            <x-filament::button wire:click="downloadPdf" icon="heroicon-o-arrow-down-tray" color="gray">
                Descargar PDF
            </x-filament::button>

            {{ $this->sendEmailAction }}
        </div>

        {{-- Movimientos --}}
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-3 py-2">Fecha</th>
                        <th class="px-3 py-2">Tipo</th>
                        <th class="px-3 py-2">Referencia</th>
                        <th class="px-3 py-2">Detalle</th>
                        <th class="px-3 py-2 text-right">Débito</th>
                        <th class="px-3 py-2 text-right">Crédito</th>
                        <th class="px-3 py-2 text-right">Saldo</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($s['movements'] as $m)
                        <tr class="bg-white dark:bg-gray-900">
                            <td class="whitespace-nowrap px-3 py-2 text-gray-600 dark:text-gray-300">{{ $m['date'] }}</td>
                            <td class="px-3 py-2">
                                @php($estilo = match ($m['type']) {
                                    'factura' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                    'abono' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                    'anticipo' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                                    default => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
                                })
                                <span class="rounded px-2 py-0.5 text-xs font-medium uppercase {{ $estilo }}">
                                    {{ $m['type'] }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 font-medium text-gray-900 dark:text-gray-100">{{ $m['reference'] }}</td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ $m['description'] }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-900 dark:text-gray-100">
                                {{ $m['debit'] > 0 ? '$'.number_format($m['debit'], 0, ',', '.') : '' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-green-700 dark:text-green-400">
                                {{ $m['credit'] > 0 ? '$'.number_format($m['credit'], 0, ',', '.') : '' }}
                            </td>
                            <td class="px-3 py-2 text-right font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                ${{ number_format($m['balance'], 0, ',', '.') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                                Este cliente no tiene movimientos en el período.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center text-gray-500 dark:border-gray-700 dark:text-gray-400">
            Elige un cliente para ver su hoja de cuenta.
        </div>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
