{{--
    Puente QZ Tray — permite imprimir ESC/POS en impresoras locales del
    cajero desde el panel (que corre en la nube).

    Flujo:
      1. El servidor encola jobs (BrowserPrintQueue) para impresoras
         con connection_type='browser'.
      2. La página Livewire despacha el evento 'qz-print-jobs'.
      3. Este script lo recibe, conecta con QZ Tray (app local) y manda
         los bytes ESC/POS (base64) a la impresora por nombre.

    Modo firmado: si /qz/certificate devuelve un certificado, se usa
    firma server-side (QZ Tray confía el certificado una vez y no vuelve
    a preguntar). Si está vacío, cae a modo sin firmar (diálogo cada vez).

    QZ Tray debe estar instalado y corriendo en la PC del cajero.
    Descarga: https://qz.io/download/
--}}
<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js"></script>
<script>
(function () {
    if (window.__qzBridgeLoaded) return;
    window.__qzBridgeLoaded = true;

    const CERT_URL = @json(route('qz.certificate'));
    const SIGN_URL = @json(route('qz.sign'));
    const CSRF = @json(csrf_token());
    const SIGN_ALGO = @json(strtoupper(config('qz.algorithm', 'SHA512')));

    function qzReady() {
        return typeof qz !== 'undefined' && qz.websocket;
    }

    // Configura QZ. Intenta modo firmado; si el certificado viene vacío,
    // cae a modo sin firmar (resolve vacío en ambas promesas).
    function configureQz() {
        if (window.__qzConfigured || !qzReady()) return;
        window.__qzConfigured = true;

        // Certificado: el server decide si hay firma o no.
        qz.security.setCertificatePromise(function (resolve, reject) {
            fetch(CERT_URL, { headers: { 'Accept': 'text/plain' } })
                .then(function (r) { return r.text(); })
                .then(function (cert) {
                    window.__qzSigned = !!(cert && cert.trim().length > 0);
                    resolve(cert || '');
                })
                .catch(function () {
                    window.__qzSigned = false;
                    resolve('');
                });
        });

        // Algoritmo de firma (QZ Tray 2.1+).
        if (qz.security.setSignatureAlgorithm) {
            qz.security.setSignatureAlgorithm(SIGN_ALGO);
        }

        // Firma: si hay certificado, firma server-side; sino, unsigned.
        qz.security.setSignaturePromise(function (toSign) {
            return function (resolve, reject) {
                if (!window.__qzSigned) {
                    resolve(); // modo sin firmar
                    return;
                }
                fetch(SIGN_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ request: toSign }),
                })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { resolve(d.signature || ''); })
                    .catch(function () { resolve(''); });
            };
        });
    }

    async function ensureConnected() {
        configureQz();
        if (qz.websocket.isActive()) return true;
        await qz.websocket.connect({ retries: 1, delay: 1 });
        return true;
    }

    async function printJobs(jobs) {
        if (!Array.isArray(jobs) || jobs.length === 0) return;

        if (!qzReady()) {
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

    document.addEventListener('livewire:init', function () {
        window.Livewire.on('qz-print-jobs', function (payload) {
            const data = Array.isArray(payload) ? payload[0] : payload;
            const jobs = (data && data.jobs) ? data.jobs : [];
            printJobs(jobs);
        });
    });

    window.qzPrintJobs = printJobs;
})();
</script>
