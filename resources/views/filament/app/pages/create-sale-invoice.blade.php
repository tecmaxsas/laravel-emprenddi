{{--
    Crear factura de venta, con guardián de salida.

    Perder una factura de veinte líneas a medio digitar por un clic en el menú es
    de las cosas que más tiempo cuestan, y no hay forma de recuperarla.

    El navegador **no permite** personalizar ese diálogo: por seguridad solo deja
    mostrar el suyo, con su propio texto y sus botones de «Salir» / «Cancelar».
    No se le puede meter un botón de «guardar borrador». Por eso el guardado va
    aquí, visible en la pantalla, y el diálogo solo sirve para frenar la salida y
    dar la oportunidad de usarlo.
--}}
<x-filament-panels::page>
    <div
        x-data="{
            sucio: false,

            marcar() {
                this.sucio = true;
            },

            limpiar() {
                this.sucio = false;
            },
        }"
        x-on:input="marcar()"
        x-on:change="marcar()"
        {{-- Al enviar el formulario ya no hay nada que perder. --}}
        x-on:submit.window="limpiar()"
        x-on:form-submitted.window="limpiar()"
        x-init="
            window.addEventListener('beforeunload', (e) => {
                if (! sucio) return;

                // El texto lo decide el navegador; asignar returnValue es lo
                // unico que activa el dialogo.
                e.preventDefault();
                e.returnValue = '';
            });
        "
    >
        <div
            x-show="sucio"
            x-cloak
            class="csi-aviso"
        >
            <span class="csi-punto"></span>
            <span>
                Tienes cambios sin guardar. Si sales ahora se pierden —
                <strong>Guardar borrador</strong> los conserva y puedes seguir después.
            </span>
        </div>

        {{ $this->form }}
    </div>

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
