{{--
    Chat con Claude.

    Dos columnas: el historial de conversaciones a la izquierda —para poder
    retomar cualquiera— y la conversación abierta a la derecha. En celular la
    lista se colapsa arriba.
--}}
<x-filament-panels::page>

    @if ($this->bloqueo)
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-500/10">
            <p class="text-sm text-warning-800 dark:text-warning-200">{{ $this->bloqueo }}</p>

            <div class="mt-3 flex flex-wrap gap-2">
                @can('ai.manage')
                    <x-filament::button
                        tag="a"
                        href="{{ \App\Filament\App\Pages\Ai\ClaudeSettings::getUrl() }}"
                        size="sm"
                        color="gray"
                        icon="heroicon-o-cog-6-tooth">
                        Ir a la configuración
                    </x-filament::button>
                @endcan

                <x-filament::button
                    tag="a"
                    href="{{ $this->whatsappRecarga }}"
                    target="_blank"
                    size="sm"
                    color="success"
                    icon="heroicon-o-chat-bubble-left-right">
                    Pedir recarga por WhatsApp
                </x-filament::button>
            </div>
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-4">

        {{-- ------------------------------------------------ historial --}}
        <aside class="lg:col-span-1">
            <x-filament::section>
                <x-slot name="heading">Conversaciones</x-slot>

                <x-filament::button
                    wire:click="nuevaConversacion"
                    class="w-full"
                    size="sm"
                    icon="heroicon-o-plus">
                    Nueva conversación
                </x-filament::button>

                <div class="mt-3 max-h-[24rem] space-y-1 overflow-y-auto lg:max-h-[32rem]">
                    @forelse ($this->conversaciones as $item)
                        <div class="group flex items-center gap-1">
                            <button
                                type="button"
                                wire:click="abrir({{ $item->id }})"
                                class="flex-1 truncate rounded-lg px-3 py-2 text-start text-sm transition
                                    {{ $this->conversationId === $item->id
                                        ? 'bg-primary-50 font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300'
                                        : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5' }}"
                                title="{{ $item->title }}">
                                {{ $item->title }}
                                <span class="block text-xs text-gray-400">
                                    {{ $item->last_message_at?->diffForHumans() }}
                                </span>
                            </button>

                            <button
                                type="button"
                                wire:click="eliminar({{ $item->id }})"
                                wire:confirm="¿Eliminar esta conversación?"
                                class="rounded-lg p-1.5 text-gray-300 opacity-0 transition hover:text-danger-500 group-hover:opacity-100"
                                title="Eliminar">
                                <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                            </button>
                        </div>
                    @empty
                        <p class="px-3 py-6 text-center text-sm text-gray-400">
                            Todavía no has conversado con Claude.
                        </p>
                    @endforelse
                </div>
            </x-filament::section>

            @if ($this->saldo !== null)
                <x-filament::section class="mt-4">
                    <x-slot name="heading">Saldo</x-slot>

                    <p class="text-2xl font-bold {{ $this->saldo > 0 ? 'text-gray-900 dark:text-white' : 'text-danger-600' }}">
                        {{ \App\Services\Ai\AiMoney::usd($this->saldo) }}
                    </p>

                    <p class="mt-1 text-xs text-gray-500">
                        ≈ {{ \App\Services\Ai\AiMoney::cop($this->saldo) }} · se descuenta según lo
                        que consume cada respuesta.
                    </p>

                    <x-filament::button
                        tag="a"
                        href="{{ $this->whatsappRecarga }}"
                        target="_blank"
                        class="mt-3 w-full"
                        size="sm"
                        color="success"
                        icon="heroicon-o-chat-bubble-left-right">
                        Recargar saldo
                    </x-filament::button>
                </x-filament::section>
            @endif
        </aside>

        {{-- ---------------------------------------------- conversación --}}
        <section class="lg:col-span-3">
            <x-filament::section>
                <x-slot name="heading">
                    {{ $this->conversacion?->title ?? 'Nueva conversación' }}
                </x-slot>

                <div class="min-h-[20rem] space-y-4">
                    @forelse ($this->mensajes as $mensaje)
                        <div class="flex {{ $mensaje->esDelUsuario() ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[85%] rounded-2xl px-4 py-3 text-sm
                                {{ $mensaje->esDelUsuario()
                                    ? 'bg-primary-600 text-white'
                                    : 'bg-gray-100 text-gray-900 dark:bg-white/5 dark:text-gray-100' }}">

                                @if ($mensaje->error)
                                    <p class="font-medium text-danger-600 dark:text-danger-400">
                                        {{ $mensaje->error }}
                                    </p>
                                @endif

                                @if ($mensaje->content)
                                    <div class="prose prose-sm max-w-none dark:prose-invert
                                        {{ $mensaje->esDelUsuario() ? 'prose-invert' : '' }}">
                                        {{-- El texto lo genera un modelo: se renderiza como markdown pero
                                             se descarta cualquier HTML crudo y los enlaces peligrosos. --}}
                                        {!! str($mensaje->content)->markdown([
                                            'html_input' => 'strip',
                                            'allow_unsafe_links' => false,
                                        ]) !!}
                                    </div>
                                @endif

                                @if (! $mensaje->esDelUsuario() && $mensaje->consultasUsadas())
                                    <p class="mt-2 border-t border-gray-200 pt-2 text-xs text-gray-500 dark:border-white/10">
                                        Consultó: {{ implode(', ', str_replace('_', ' ', $mensaje->consultasUsadas())) }}
                                    </p>
                                @endif

                                <p class="mt-1 text-xs opacity-60">
                                    {{ $mensaje->created_at?->format('d/m/Y h:i a') }}
                                </p>
                            </div>
                        </div>
                    @empty
                        <div class="py-6 text-center">
                            <p class="text-sm text-gray-500">¿Sobre qué quieres preguntar?</p>

                            <div class="mt-4 flex flex-wrap justify-center gap-2">
                                @foreach ($this->sugerencias as $sugerencia)
                                    <button
                                        type="button"
                                        wire:click="usarSugerencia(@js($sugerencia))"
                                        class="rounded-full border border-gray-200 px-3 py-1.5 text-xs text-gray-600
                                               transition hover:border-primary-400 hover:text-primary-600
                                               dark:border-white/10 dark:text-gray-400">
                                        {{ $sugerencia }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforelse

                    <div wire:loading wire:target="preguntar" class="flex justify-start">
                        <div class="rounded-2xl bg-gray-100 px-4 py-3 text-sm text-gray-500 dark:bg-white/5">
                            Consultando tus datos…
                        </div>
                    </div>
                </div>

                <form wire:submit.prevent="preguntar" class="mt-4 flex items-end gap-2 border-t border-gray-100 pt-4 dark:border-white/10">
                    <textarea
                        wire:model="pregunta"
                        rows="2"
                        placeholder="Ej. ¿Cuánto vendí la semana pasada?"
                        @disabled($this->bloqueo)
                        class="flex-1 rounded-xl border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500
                               disabled:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-white"
                        x-on:keydown.enter.prevent="if (! $event.shiftKey) $wire.preguntar()"></textarea>

                    <x-filament::button
                        type="submit"
                        icon="heroicon-o-paper-airplane"
                        :disabled="(bool) $this->bloqueo"
                        wire:loading.attr="disabled"
                        wire:target="preguntar">
                        <span wire:loading.remove wire:target="preguntar">Enviar</span>
                        <span wire:loading wire:target="preguntar">Pensando…</span>
                    </x-filament::button>
                </form>

                <p class="mt-2 text-xs text-gray-400">
                    Claude solo puede consultar: no crea, modifica ni borra nada. Verifica las cifras
                    importantes en su reporte antes de tomar una decisión.
                </p>
            </x-filament::section>
        </section>
    </div>

</x-filament-panels::page>
