<x-filament-panels::page>

    <form wire:submit.prevent="guardar">
        {{ $this->form }}

        <div class="mt-4 flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Guardar
            </x-filament::button>
        </div>
    </form>

    @unless ($this->usaCuentaPropia)
        <x-filament::section>
            <x-slot name="heading">Saldo</x-slot>
            <x-slot name="description">
                Se descuenta según lo que consume cada respuesta. Para recargar, escríbele al equipo
                comercial de Tecmax por WhatsApp.
            </x-slot>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-3xl font-bold {{ $this->saldo > 0 ? 'text-gray-900 dark:text-white' : 'text-danger-600' }}">
                        ${{ number_format($this->saldo, 0, ',', '.') }}
                    </p>
                    @if ($this->saldo <= 0)
                        <p class="mt-1 text-sm text-danger-600">
                            Sin saldo: Claude no responderá hasta que recargues.
                        </p>
                    @endif
                </div>

                <x-filament::button
                    tag="a"
                    href="{{ $this->whatsappRecarga }}"
                    target="_blank"
                    color="success"
                    icon="heroicon-o-chat-bubble-left-right">
                    Solicitar recarga por WhatsApp
                </x-filament::button>
            </div>

            @if ($this->movimientos->isNotEmpty())
                <div class="mt-6 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-gray-200 text-start text-xs uppercase text-gray-500 dark:border-white/10">
                            <tr>
                                <th class="py-2 text-start font-medium">Fecha</th>
                                <th class="py-2 text-start font-medium">Movimiento</th>
                                <th class="py-2 text-start font-medium">Detalle</th>
                                <th class="py-2 text-end font-medium">Valor</th>
                                <th class="py-2 text-end font-medium">Saldo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($this->movimientos as $movimiento)
                                <tr>
                                    <td class="py-2 whitespace-nowrap text-gray-500">
                                        {{ $movimiento->created_at?->format('d/m/Y h:i a') }}
                                    </td>
                                    <td class="py-2">
                                        {{ \App\Models\AiCreditMovement::TYPES[$movimiento->type] ?? $movimiento->type }}
                                    </td>
                                    <td class="py-2 text-gray-500">{{ $movimiento->description }}</td>
                                    <td class="py-2 text-end {{ $movimiento->amount_cop < 0 ? 'text-danger-600' : 'text-success-600' }}">
                                        ${{ number_format((float) $movimiento->amount_cop, 0, ',', '.') }}
                                    </td>
                                    <td class="py-2 text-end font-medium">
                                        ${{ number_format((float) $movimiento->balance_after, 0, ',', '.') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endunless

</x-filament-panels::page>
