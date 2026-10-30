{{--
    Estilos de los reportes del hotel.

    En un parcial porque son dos reportes con la misma barra de botones y la
    misma regla de impresión. Escrito dos veces, el día que haya que arreglar
    el salto de página se arregla en uno y el otro sigue imprimiendo mal — que
    es lo que ya pasó con los cuatro formatos térmicos.
--}}
<style>
    .hr-toolbar { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
    .hr-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 14px; border-radius: 8px; border: 0;
        font-weight: 700; font-size: 13px; text-decoration: none; cursor: pointer;
        min-height: 42px;
    }
    .hr-btn-excel { background: #16a34a; color: #fff; }
    .hr-btn-detail { background: #4f46e5; color: #fff; }
    .hr-btn-print { background: #475569; color: #fff; }

    .hr-kpis {
        display: grid; gap: 10px; margin-top: 14px;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
    .hr-kpi { border-radius: 10px; padding: 13px 15px; color: #fff; }
    .hr-kpi-k { font-size: 10.5px; text-transform: uppercase; font-weight: 700; opacity: .85; }
    .hr-kpi-v { font-size: 23px; font-weight: 900; margin-top: 2px; line-height: 1.1; }
    .hr-kpi-h { font-size: 10.5px; opacity: .8; margin-top: 2px; }

    .hr-card {
        margin-top: 14px; background: #fff; border: 1px solid #e2e8f0;
        border-radius: 10px; overflow: auto;
    }
    .dark .hr-card { background: #1e293b; border-color: #334155; }

    .hr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .hr-table thead { background: #f1f5f9; }
    .dark .hr-table thead { background: #0f172a; }
    .hr-table th { padding: 9px 13px; text-align: left; font-size: 11px; text-transform: uppercase; color: #475569; }
    .dark .hr-table th { color: #94a3b8; }
    .hr-table td { padding: 7px 13px; border-top: 1px solid #f1f5f9; color: #1e293b; }
    .dark .hr-table td { border-color: #334155; color: #e2e8f0; }
    .hr-table tfoot { background: #f1f5f9; font-weight: 700; }
    .dark .hr-table tfoot { background: #0f172a; }
    .hr-table tfoot th { font-size: 12.5px; text-transform: none; color: #0f172a; }
    .dark .hr-table tfoot th { color: #f1f5f9; }
    .hr-r { text-align: right; }
    .hr-mono { font-family: ui-monospace, monospace; }
    .hr-dim { color: #94a3b8; }
    .hr-strong { font-weight: 700; }

    /* El fin de semana se resalta: en un hotel es cuando se juega el mes. */
    .hr-finde td { background: #f8fafc; }
    .dark .hr-finde td { background: #0f172a; }

    .hr-empty {
        margin-top: 16px; padding: 30px; text-align: center; font-size: 13.5px;
        color: #64748b; background: #f8fafc; border-radius: 10px; border: 1px dashed #cbd5e1;
    }
    .dark .hr-empty { color: #94a3b8; background: #1e293b; border-color: #475569; }

    /* Barra de ocupación: un número del 0 al 100 se lee mejor como barra que
       como cifra, sobre todo cuando son treinta filas seguidas. */
    .hr-bar { position: relative; height: 16px; background: #e2e8f0; border-radius: 4px; min-width: 70px; }
    .dark .hr-bar { background: #334155; }
    .hr-bar span {
        position: absolute; inset: 0 auto 0 0; border-radius: 4px; background: #4f46e5;
    }
    .hr-bar b {
        position: relative; display: block; text-align: center; font-size: 10.5px;
        line-height: 16px; color: #0f172a; font-weight: 700;
    }
    .dark .hr-bar b { color: #f1f5f9; }

    @media print {
        @page { size: A4 landscape; margin: 10mm; }
        body * { visibility: hidden !important; }
        .hr-print, .hr-print * { visibility: visible !important; }
        .hr-print {
            position: absolute !important; left: 0 !important; top: 0 !important;
            width: 100% !important; background: #fff !important; color: #000 !important;
        }
        .hr-toolbar { display: none !important; }
        /* Las barras se vuelven ilegibles en blanco y negro; el número sigue. */
        .hr-bar { background: transparent !important; }
        .hr-bar span { display: none !important; }
    }

    @media (max-width: 640px) {
        .hr-btn { width: 100%; }
        .hr-table { font-size: 11.5px; }
        .hr-table th, .hr-table td { padding: 6px 8px; }
    }
</style>
