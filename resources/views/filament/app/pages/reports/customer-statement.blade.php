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

            {{ $this->shareWhatsappAction }}

            {{ $this->registerAdvanceAction }}
        </div>

        {{-- PESTAÑAS
             «Cuánto debe» y «qué le hemos vendido» son dos preguntas distintas
             sobre el mismo cliente. La segunda obligaba a irse al listado de
             facturas y filtrar; con el cliente al teléfono, eso no pasa. --}}
        <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700">
            @foreach (['movimientos' => 'Movimientos', 'ventas' => 'Ventas del cliente'] as $clave => $rotulo)
                <button type="button" wire:click="verPestana('{{ $clave }}')"
                        class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition
                            {{ $pestana === $clave
                                ? 'border-primary-600 text-primary-600 dark:border-primary-400 dark:text-primary-400'
                                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}">
                    {{ $rotulo }}
                </button>
            @endforeach
        </div>

        @if ($pestana === 'ventas')
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-2">Factura</th>
                            <th class="px-3 py-2">Fecha</th>
                            <th class="px-3 py-2">Vence</th>
                            <th class="px-3 py-2 text-right">Total</th>
                            <th class="px-3 py-2 text-right">Pagado</th>
                            <th class="px-3 py-2 text-right">Saldo</th>
                            <th class="px-3 py-2">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($this->ventasDelCliente as $f)
                            @php($saldo = (float) $f->balance)
                            <tr class="bg-white dark:bg-gray-900">
                                <td class="whitespace-nowrap px-3 py-2 font-medium text-gray-900 dark:text-gray-100">
                                    <a href="{{ \App\Filament\App\Resources\SaleInvoiceResource::getUrl('view', ['record' => $f]) }}"
                                       class="text-primary-600 hover:underline dark:text-primary-400">{{ $f->fullNumber() }}</a>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-gray-600 dark:text-gray-300">{{ $f->date?->format('Y-m-d') }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-gray-600 dark:text-gray-300">{{ $f->due_date?->format('Y-m-d') ?? '—' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">${{ number_format((float) $f->total, 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-green-700 dark:text-green-400">${{ number_format((float) $f->paid_amount, 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right font-semibold tabular-nums {{ $saldo > 0.01 ? 'text-red-600 dark:text-red-400' : 'text-gray-500' }}">
                                    ${{ number_format($saldo, 0, ',', '.') }}
                                </td>
                                <td class="px-3 py-2 text-xs uppercase text-gray-500 dark:text-gray-400">
                                    {{ \App\Models\SaleInvoice::PAYMENT_STATUSES[$f->payment_status] ?? $f->payment_status }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                                    Este cliente no tiene ventas en el período.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else

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
                        <th class="px-3 py-2"></th>
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
                                    'aplicacion' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
                                    'nota' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
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
                            {{-- Corregir o borrar el cobro desde aquí.
                                 La hoja del cliente es donde se descubre el
                                 error; obligar a ir a buscar la factura es
                                 como los errores se quedan sin corregir. --}}
                            <td class="whitespace-nowrap px-3 py-2 text-right">
                                @if (! empty($m['payment_id']) && auth()->user()?->can('sales.receive_payment'))
                                    <div class="flex items-center justify-end gap-1">
                                        {{ ($this->editPaymentAction)(['payment' => $m['payment_id']]) }}
                                        {{ ($this->deletePaymentAction)(['payment' => $m['payment_id']]) }}
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                                Este cliente no tiene movimientos en el período.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @endif
    @else
        <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center text-gray-500 dark:border-gray-700 dark:text-gray-400">
            Elige un cliente para ver su hoja de cuenta.
        </div>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
