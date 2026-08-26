{{--
    Puente de impresión de comandas por navegador.

    Escucha 'kot-print-browser' y abre una ventana por comanda contra
    /app/restaurant/kot/print/{id}, que auto-imprime al cargar. Se usa cuando
    la comanda no tiene impresora asignada y desde la reimpresión manual.

    Va como render hook global (igual que el puente de QZ Tray) para que
    funcione tanto en el POS de restaurante como en el listado de comandas,
    sin duplicar el listener en cada vista.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        window.Livewire.on('kot-print-browser', (event) => {
            const payload = Array.isArray(event) ? event[0] : event;
            const ids = payload?.ticketIds ?? [];

            // Escalonadas: el navegador ignora varias ventanas abiertas en el
            // mismo tick.
            ids.forEach((id, i) => {
                setTimeout(() => {
                    window.open(
                        '{{ url('/app/restaurant/kot/print') }}/' + id,
                        'kot-print-' + id,
                        'width=420,height=720'
                    );
                }, i * 400);
            });
        });
    });
</script>
