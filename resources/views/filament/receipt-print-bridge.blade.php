{{--
    Puente de impresión del recibo de venta por navegador.

    Escucha 'pos-print-ticket' y abre la ventana del tiquete, que auto-imprime
    al cargar. Se dispara cuando la sede no tiene impresora de caja activa (o
    la impresión física falló), tanto desde el POS retail como desde el de
    restaurante.

    Acepta invoiceId (una factura) o invoiceIds (varias, cuando se dividió la
    cuenta en restaurante).

    Va como render hook global para no repetir el listener en cada página.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        window.Livewire.on('pos-print-ticket', (event) => {
            const payload = Array.isArray(event) ? event[0] : event;

            const ids = payload?.invoiceIds
                ?? (payload?.invoiceId ? [payload.invoiceId] : []);

            // Escalonadas: el navegador ignora varias ventanas abiertas en el
            // mismo tick.
            ids.forEach((id, i) => {
                setTimeout(() => {
                    window.open(
                        '{{ url('/app/pos/print') }}/' + id,
                        'pos-print-' + id,
                        'width=420,height=720'
                    );
                }, i * 400);
            });
        });
    });
</script>
