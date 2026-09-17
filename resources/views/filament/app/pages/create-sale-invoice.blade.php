{{--
    Crear factura de venta.

    Es una copia de la vista de Filament (`resources/pages/create-record`) con un
    solo agregado: el aviso visible de cambios sin guardar. Todo lo demas —el
    `<form>`, los botones, el guardian de navegacion— va tal cual, porque
    quitarlo es exactamente lo que dejo la pantalla sin boton de guardar.

    El guardian es el de Filament, no uno propio: compara un hash de los datos
    del formulario contra el que se guardo al montar, asi que sabe de verdad si
    algo cambio. Una version casera que marca «sucio» con cualquier tecla
    pregunta tambien cuando el usuario escribio y borro.
--}}
<x-filament-panels::page
    @class([
        'fi-resource-create-record-page',
        'fi-resource-'.str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    {{-- El aviso se dibuja aparte del formulario: adentro quedaria dentro del
         <form> y cualquier cambio en su marcado afectaria al envio. --}}
    <div
        x-data="{ sucio: false }"
        x-on:input.window="sucio = true"
        x-on:form-submitted.window="sucio = false"
    >
        <div x-show="sucio" x-cloak class="csi-aviso">
            <span class="csi-punto"></span>
            <span>
                Tienes cambios sin guardar. Si sales ahora se pierden —
                <strong>Guardar borrador</strong> los conserva y puedes seguir después.
            </span>
        </div>
    </div>

    <x-filament-panels::form
        id="form"
        :wire:key="$this->getId().'.forms.'.$this->getFormStatePath()"
        wire:submit="create"
    >
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>

    <x-filament-panels::page.unsaved-data-changes-alert />

    <style>
        [x-cloak] { display: none !important; }

        .csi-aviso {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 14px; padding: 10px 14px;
            background: #fef3c7; color: #92400e;
            border: 1px solid #fcd34d; border-radius: 10px;
            font-size: 13px;
        }

        .csi-punto {
            width: 8px; height: 8px; border-radius: 999px;
            background: #f59e0b; flex: none;
        }

        .dark .csi-aviso {
            background: #422006; color: #fde68a; border-color: #854d0e;
        }
    </style>
</x-filament-panels::page>
