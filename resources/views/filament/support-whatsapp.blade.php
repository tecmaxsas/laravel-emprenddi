@php
    $numero = preg_replace('/\D/', '', (string) config('services.support.whatsapp'));
@endphp

@if ($numero !== '')
    {{--
        Botón flotante de soporte técnico.

        Se oculta en las pantallas de POS: las tres tienen una barra de acciones
        fija abajo (métodos de pago, cobrar) y el botón quedaría encima,
        tapándole el clic al cajero justo en el momento de cobrar.
    --}}
    <a href="https://wa.me/{{ $numero }}"
       target="_blank"
       rel="noopener noreferrer"
       class="ep-support-wa"
       aria-label="Escribir a soporte técnico por WhatsApp"
       title="Soporte técnico">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor" aria-hidden="true">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.149-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.148-.669-1.611-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884a9.82 9.82 0 016.99 2.898 9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.464 3.488"/>
        </svg>
    </a>

    <style>
        .ep-support-wa {
            position: fixed;
            right: 1.25rem;
            bottom: 1.25rem;
            z-index: 40; /* debajo de modales y notificaciones de Filament */
            display: flex;
            align-items: center;
            justify-content: center;
            width: 3.25rem;
            height: 3.25rem;
            border-radius: 9999px;
            background: #25d366;
            color: #fff;
            box-shadow: 0 6px 20px -4px rgba(37, 211, 102, .55), 0 2px 6px rgba(0, 0, 0, .2);
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .ep-support-wa:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 26px -4px rgba(37, 211, 102, .7), 0 2px 6px rgba(0, 0, 0, .25);
        }

        .ep-support-wa:focus-visible {
            outline: 2px solid #fff;
            outline-offset: 3px;
        }

        /* Las pantallas de POS tienen barra de acciones fija abajo. */
        body.ep-hide-support-wa .ep-support-wa { display: none; }

        @media (prefers-reduced-motion: reduce) {
            .ep-support-wa { transition: none; }
            .ep-support-wa:hover { transform: none; }
        }
    </style>

    <script>
        (() => {
            // Rutas donde estorba: barra inferior fija de cobro.
            const sinBoton = ['/app/pos', '/app/restaurant-pos', '/app/parking'];

            const evaluar = () => {
                const path = window.location.pathname.replace(/\/+$/, '');
                const tapa = sinBoton.some((r) => path === r || path.startsWith(r + '/'));
                document.body.classList.toggle('ep-hide-support-wa', tapa);
            };

            evaluar();
            // Livewire/SPA navega sin recargar: reevaluar al cambiar de página.
            document.addEventListener('livewire:navigated', evaluar);
        })();
    </script>
@endif
