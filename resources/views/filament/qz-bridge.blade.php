{{--
    Puente QZ Tray — permite imprimir ESC/POS en impresoras locales del
    cajero desde el panel (que corre en la nube).

    Flujo:
      1. El servidor encola jobs (BrowserPrintQueue) para impresoras
         con connection_type='browser'.
      2. La página Livewire despacha el evento 'qz-print-jobs'.
      3. Este script lo recibe, conecta con QZ Tray (app local) y manda
         los bytes ESC/POS (base64) a la impresora por nombre.

    QZ Tray debe estar instalado y corriendo en la PC del cajero.
    Descarga: https://qz.io/download/
--}}
<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js"></script>
<script>
(function () {
    // Evita doble registro si el partial se incluye más de una vez.
    if (window.__qzBridgeLoaded) return;
    window.__qzBridgeLoaded = true;

    function qzReady() {
        return typeof qz !== 'undefined' && qz.websocket;
    }

    // Configura QZ en modo "sin firmar" (unsigned). QZ Tray mostrará un
    // diálogo de permiso la primera vez — el cajero marca "Recordar" + "Permitir".
    function configureUnsigned() {
        if (window.__qzConfigured || !qzReady()) return;
        qz.security.setCertificatePromise(function (resolve) { resolve(); });
        qz.security.setSignaturePromise(function () {
            return function (resolve) { resolve(); };
        });
        window.__qzConfigured = true;
    }

    async function ensureConnected() {
        configureUnsigned();
        if (qz.websocket.isActive()) return true;
        await qz.websocket.connect({ retries: 1, delay: 1 });
        return true;
    }

    async function printJobs(jobs) {
        if (!Array.isArray(jobs) || jobs.length === 0) return;

        if (!qzReady()) {
            window.$wireToast && window.$wireToast('QZ Tray no cargó. Recargá la página.', 'danger');
            console.error('[QZ] qz-tray.js no disponible');
            return;
        }

        try {
            await ensureConnected();
        } catch (e) {
            console.error('[QZ] No se pudo conectar a QZ Tray', e);
            alert('No se pudo conectar con QZ Tray.\n\n' +
                  'Verificá que QZ Tray esté instalado y corriendo en esta PC.\n' +
                  'Descarga: https://qz.io/download/');
            return;
        }

        for (const job of jobs) {
            if (!job.printer_name) continue;
            try {
                const cfg = qz.configs.create(job.printer_name, { encoding: 'CP858' });
                await qz.print(cfg, [{
                    type: 'raw',
                    format: 'base64',
                    data: job.payload_b64,
                }]);
                console.log('[QZ] Impreso:', job.label || job.printer_name);
            } catch (e) {
                console.error('[QZ] Error imprimiendo en ' + job.printer_name, e);
                alert('Error imprimiendo en "' + job.printer_name + '":\n' + e +
                      '\n\nVerificá que el nombre de la impresora sea exacto.');
            }
        }
    }

    // Escucha el evento Livewire que dispara la página al terminar
    // de enviar a cocina / cobrar.
    document.addEventListener('livewire:init', function () {
        window.Livewire.on('qz-print-jobs', function (payload) {
            // Livewire 3 entrega los params como objeto o como [objeto].
            const data = Array.isArray(payload) ? payload[0] : payload;
            const jobs = (data && data.jobs) ? data.jobs : [];
            printJobs(jobs);
        });
    });

    // Expuesto global para el botón "Probar impresión" del panel.
    window.qzPrintJobs = printJobs;
})();
</script>
