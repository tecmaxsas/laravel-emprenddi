{{--
    Estilos de la recepción.

    Viven en un parcial aparte y no dentro de la pantalla porque la pantalla ya
    es larga y mezclar 300 líneas de CSS con la lógica de los modales hace que
    cualquier cambio sea una búsqueda.

    TODO COLOR ESTÁ DECLARADO DOS VECES, claro y oscuro. No es adorno: el POS
    de retail tuvo el bug de que en modo oscuro no se veía lo que se digitaba
    en el buscador, porque los inputs heredaban el fondo del tema y el texto
    se quedaba oscuro. Un campo invisible en una pantalla de cobro es un cobro
    mal digitado, así que aquí cada input lleva su color de texto y de fondo
    escritos, sin herencias.
--}}
<style>
    /* ---------------------------------------------------------------- */
    /*  Turno de caja */
    /* ---------------------------------------------------------------- */
    .hd-shift {
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap; gap: 8px;
        border-radius: 8px; padding: 9px 14px; margin-bottom: 12px;
        font-size: 12.5px;
    }
    .hd-shift-open { border: 1px solid #16a34a; background: #dcfce7; color: #166534; }
    .hd-shift-closed { border: 1px solid #dc2626; background: #fee2e2; color: #991b1b; }
    .dark .hd-shift-open { background: #052e16; color: #86efac; border-color: #166534; }
    .dark .hd-shift-closed { background: #450a0a; color: #fca5a5; border-color: #991b1b; }

    /* ---------------------------------------------------------------- */
    /*  Barra superior */
    /* ---------------------------------------------------------------- */
    .hd-bar {
        display: flex; flex-wrap: wrap; align-items: flex-end; gap: 14px;
        padding: 12px 14px; margin-bottom: 14px;
        border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc;
    }
    .dark .hd-bar { border-color: #334155; background: #1e293b; }

    .hd-bar label, .hd-field label {
        display: block; font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .03em; color: #64748b; margin-bottom: 4px;
    }
    .dark .hd-bar label, .dark .hd-field label { color: #94a3b8; }

    .hd-hotels { flex: 0 1 auto; }
    .hd-tabs { display: flex; flex-wrap: wrap; gap: 6px; }
    .hd-tab {
        padding: 7px 14px; border-radius: 8px; border: 1px solid #cbd5e1;
        background: #fff; color: #334155; font-weight: 600; font-size: 13px; cursor: pointer;
    }
    .dark .hd-tab { background: #0f172a; border-color: #475569; color: #e2e8f0; }
    .hd-tab.is-active { background: #4f46e5; border-color: #4f46e5; color: #fff; }

    .hd-search { flex: 1 1 280px; min-width: 240px; }
    .hd-hint { display: block; font-size: 11px; color: #94a3b8; margin-top: 3px; }

    .hd-kpis { display: flex; gap: 8px; flex-wrap: wrap; }
    .hd-kpi {
        min-width: 74px; padding: 7px 12px; border-radius: 8px; text-align: center;
        border: 1px solid transparent;
    }
    .hd-kpi-num { display: block; font-size: 20px; font-weight: 800; line-height: 1.1; }
    .hd-kpi-label { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; }
    .hd-kpi-green { background: #dcfce7; color: #166534; border-color: #86efac; }
    .hd-kpi-red { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
    .hd-kpi-blue { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
    .hd-kpi-gray { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }
    .dark .hd-kpi-green { background: #052e16; color: #86efac; border-color: #166534; }
    .dark .hd-kpi-red { background: #450a0a; color: #fca5a5; border-color: #991b1b; }
    .dark .hd-kpi-blue { background: #082f49; color: #93c5fd; border-color: #1e40af; }
    .dark .hd-kpi-gray { background: #0f172a; color: #cbd5e1; border-color: #475569; }

    /* ---------------------------------------------------------------- */
    /*  Llegadas y salidas del día */
    /* ---------------------------------------------------------------- */
    .hd-today { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 14px; }
    .hd-today-col {
        flex: 1 1 320px; padding: 10px 12px;
        border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;
    }
    .dark .hd-today-col { border-color: #334155; background: #1e293b; }
    .hd-today-head {
        font-size: 13px; font-weight: 700; color: #334155;
        display: flex; align-items: center; gap: 8px; margin-bottom: 8px;
    }
    .dark .hd-today-head { color: #e2e8f0; }
    .hd-pill {
        background: #4f46e5; color: #fff; border-radius: 999px;
        padding: 1px 8px; font-size: 11px; font-weight: 700;
    }

    .hd-chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .hd-chip {
        text-align: left; padding: 7px 10px; border-radius: 8px;
        border: 1px solid #cbd5e1; background: #f8fafc; cursor: pointer;
        /* 44px de alto mínimo: es el tamaño con el que un dedo acierta. El
           recepcionista también usa esta pantalla en tablet. */
        min-height: 44px;
        touch-action: manipulation;
    }
    .dark .hd-chip { border-color: #475569; background: #0f172a; }
    .hd-chip-late { border-color: #f59e0b; background: #fffbeb; }
    .dark .hd-chip-late { border-color: #b45309; background: #451a03; }
    .hd-chip-main { display: block; font-size: 13px; font-weight: 700; color: #1e293b; }
    .dark .hd-chip-main { color: #f1f5f9; }
    .hd-chip-sub { display: block; font-size: 11px; color: #64748b; }
    .dark .hd-chip-sub { color: #94a3b8; }
    .hd-chip-flag {
        display: inline-block; margin-top: 3px; font-size: 10px; font-weight: 700;
        text-transform: uppercase; color: #b45309;
    }

    /* ---------------------------------------------------------------- */
    /*  El mapa */
    /* ---------------------------------------------------------------- */
    .hd-zone { margin-bottom: 16px; }
    .hd-zone-head {
        display: flex; align-items: baseline; gap: 10px;
        padding-bottom: 6px; margin-bottom: 8px; border-bottom: 1px solid #e2e8f0;
    }
    .dark .hd-zone-head { border-color: #334155; }
    .hd-zone-head h3 { font-size: 14px; font-weight: 700; margin: 0; color: #1e293b; }
    .dark .hd-zone-head h3 { color: #f1f5f9; }
    .hd-zone-count { font-size: 11.5px; color: #94a3b8; }

    .hd-grid {
        display: grid; gap: 8px;
        grid-template-columns: repeat(auto-fill, minmax(118px, 1fr));
    }
    .hd-tile {
        position: relative; display: block; text-align: left;
        padding: 8px 10px; border: 0; border-radius: 10px; cursor: pointer;
        color: #fff; min-height: 86px;
        touch-action: manipulation;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .2);
    }
    .hd-tile-off { opacity: .55; cursor: not-allowed; }
    .hd-tile-top { display: flex; justify-content: space-between; align-items: flex-start; }
    .hd-tile-num { font-size: 19px; font-weight: 800; line-height: 1.05; font-family: ui-monospace, monospace; }
    .hd-tile-badge { font-size: 13px; }
    .hd-tile-guest { font-size: 11.5px; font-weight: 700; margin-top: 3px; }
    .hd-tile-meta { font-size: 10.5px; opacity: .9; }
    .hd-tile-amount { font-size: 12px; font-weight: 700; margin-top: 3px; }

    .hd-legend { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 6px; font-size: 11.5px; color: #64748b; }
    .dark .hd-legend { color: #94a3b8; }
    .hd-legend-item { display: inline-flex; align-items: center; gap: 5px; }
    .hd-legend-item i { width: 11px; height: 11px; border-radius: 3px; display: inline-block; }

    .hd-empty {
        padding: 28px; text-align: center; font-size: 13.5px; color: #64748b;
        border: 1px dashed #cbd5e1; border-radius: 10px; background: #f8fafc;
    }
    .dark .hd-empty { color: #94a3b8; border-color: #475569; background: #1e293b; }

    /* ---------------------------------------------------------------- */
    /*  Modales */
    /* ---------------------------------------------------------------- */
    .hd-overlay {
        position: fixed; inset: 0; z-index: 60;
        background: rgba(15, 23, 42, .6);
        display: flex; align-items: flex-start; justify-content: center;
        padding: 24px 12px; overflow-y: auto;
    }
    /* Los submodales van por encima del folio, que ya es un modal. */
    .hd-overlay-top { z-index: 70; }

    .hd-modal {
        width: 100%; max-width: 560px; background: #fff; border-radius: 12px;
        box-shadow: 0 20px 45px rgba(15, 23, 42, .35); overflow: hidden;
    }
    .dark .hd-modal { background: #1e293b; }
    .hd-modal-sm { max-width: 460px; }
    .hd-modal-lg { max-width: 760px; }
    .hd-modal-xl { max-width: 1040px; }

    .hd-modal-head {
        display: flex; justify-content: space-between; align-items: center; gap: 10px;
        padding: 12px 16px; color: #fff;
    }
    .hd-modal-head h2 {
        margin: 0; font-size: 15.5px; font-weight: 700;
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    }
    .hd-modal-head code {
        background: rgba(255, 255, 255, .22); padding: 1px 7px; border-radius: 5px;
        font-family: ui-monospace, monospace;
    }
    .hd-head-sub { font-size: 12px; font-weight: 500; opacity: .85; }
    .hd-head-green { background: #16a34a; }
    .hd-head-indigo { background: #4f46e5; }
    .hd-head-blue { background: #0ea5e9; }
    .hd-head-amber { background: #b45309; }
    .hd-head-dark { background: #1f2937; }

    .hd-close {
        background: rgba(255, 255, 255, .2); border: 0; color: #fff;
        width: 30px; height: 30px; border-radius: 7px; cursor: pointer; font-size: 14px;
    }

    .hd-modal-body { padding: 14px 16px; max-height: 68vh; overflow-y: auto; }

    .hd-modal-foot {
        display: flex; justify-content: flex-end; gap: 8px;
        padding: 11px 16px; border-top: 1px solid #e2e8f0; background: #f8fafc;
    }
    .dark .hd-modal-foot { border-color: #334155; background: #0f172a; }
    .hd-foot-wrap { flex-wrap: wrap; }
    .hd-btn-grow { flex: 1 1 200px; }

    /* ---------------------------------------------------------------- */
    /*  Campos. Fondo y color escritos a mano: ver la nota de arriba. */
    /* ---------------------------------------------------------------- */
    .hd-field { margin-bottom: 11px; }
    .hd-bottom { display: flex; flex-direction: column; justify-content: flex-end; }

    .hd-input {
        width: 100%; padding: 9px 11px; font-size: 14px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        background: #fff; color: #0f172a;
        /* 16px en móvil evita que el navegador haga zoom al enfocar. */
        min-height: 42px;
    }
    .dark .hd-input { background: #0f172a; border-color: #475569; color: #f1f5f9; }
    .hd-input::placeholder { color: #94a3b8; }
    .dark .hd-input::placeholder { color: #64748b; }
    .hd-input:focus { outline: 2px solid #4f46e5; outline-offset: -1px; }
    .hd-input-big { font-size: 20px; font-weight: 700; text-align: right; }

    .hd-cols-2, .hd-cols-3, .hd-cols-4 { display: grid; gap: 10px; }
    .hd-cols-2 { grid-template-columns: repeat(2, 1fr); }
    .hd-cols-3 { grid-template-columns: repeat(3, 1fr); }
    .hd-cols-4 { grid-template-columns: repeat(4, 1fr); }
    .hd-span-2 { grid-column: span 2; }

    .hd-check { display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #334155; cursor: pointer; }
    .dark .hd-check { color: #e2e8f0; }
    .hd-check input { width: 18px; height: 18px; }

    /* Contado o credito: dos botones grandes y excluyentes, no un
       desplegable. Es la decision que cambia si entra plata o no, y
       esconderla en una lista es como no preguntarla. */
    .hd-modo { display: flex; gap: 8px; margin-bottom: 11px; }
    .hd-modo-btn {
        flex: 1; padding: 11px 10px; border-radius: 9px; cursor: pointer;
        border: 2px solid #cbd5e1; background: #fff; color: #475569;
        font-weight: 700; font-size: 13.5px; min-height: 46px;
    }
    .dark .hd-modo-btn { border-color: #475569; background: #0f172a; color: #cbd5e1; }
    .hd-modo-btn.is-active { border-color: #4f46e5; background: #eef2ff; color: #3730a3; }
    .dark .hd-modo-btn.is-active { background: #1e1b4b; color: #c7d2fe; }

    .hd-strong-row { font-weight: 800; }

    .hd-section {
        margin-top: 12px; padding: 11px 12px;
        border: 1px solid #e2e8f0; border-radius: 9px; background: #f8fafc;
    }
    .dark .hd-section { border-color: #334155; background: #0f172a; }
    .hd-section-head {
        font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 8px;
        display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap;
    }
    .dark .hd-section-head { color: #e2e8f0; }

    .hd-results {
        margin-top: 5px; border: 1px solid #e2e8f0; border-radius: 8px;
        background: #fff; max-height: 220px; overflow-y: auto;
    }
    .dark .hd-results { border-color: #475569; background: #1e293b; }
    .hd-result {
        display: flex; justify-content: space-between; align-items: center; gap: 10px;
        width: 100%; text-align: left; padding: 9px 11px; min-height: 44px;
        border: 0; border-bottom: 1px solid #f1f5f9; background: transparent; cursor: pointer;
        font-size: 13px; color: #1e293b;
    }
    .dark .hd-result { color: #f1f5f9; border-color: #334155; }
    .hd-result:last-child { border-bottom: 0; }
    .hd-result span { font-size: 11.5px; color: #64748b; }
    .dark .hd-result span { color: #94a3b8; }

    .hd-picked {
        display: flex; justify-content: space-between; align-items: center; gap: 10px;
        padding: 9px 11px; border-radius: 8px; font-size: 13.5px;
        border: 1px solid #86efac; background: #dcfce7; color: #166534;
    }
    .dark .hd-picked { border-color: #166534; background: #052e16; color: #86efac; }
    .hd-link { background: none; border: 0; color: inherit; text-decoration: underline; cursor: pointer; font-size: 12px; }

    /* ---------------------------------------------------------------- */
    /*  Botones */
    /* ---------------------------------------------------------------- */
    .hd-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        padding: 9px 15px; border: 0; border-radius: 8px; cursor: pointer;
        font-weight: 700; font-size: 13px; min-height: 42px;
        text-decoration: none; touch-action: manipulation;
    }
    .hd-btn-block { width: 100%; }
    .hd-btn-gray { background: #e2e8f0; color: #334155; }
    .dark .hd-btn-gray { background: #334155; color: #e2e8f0; }
    .hd-btn-green { background: #16a34a; color: #fff; }
    .hd-btn-indigo { background: #4f46e5; color: #fff; }
    .hd-btn-amber { background: #b45309; color: #fff; }
    .hd-btn-danger { background: #991b1b; color: #fff; }
    .hd-btn-dark { background: #166534; color: #fff; }
    .hd-row-btns { display: flex; flex-wrap: wrap; gap: 8px; }

    /* ---------------------------------------------------------------- */
    /*  Folio */
    /* ---------------------------------------------------------------- */
    .hd-summary {
        display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        padding: 11px 12px; border-radius: 9px;
        border: 1px solid #e2e8f0; background: #f8fafc; margin-bottom: 12px;
    }
    .dark .hd-summary { border-color: #334155; background: #0f172a; }
    .hd-sum-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: #94a3b8; }
    .hd-sum-val { font-size: 13.5px; font-weight: 600; color: #1e293b; }
    .dark .hd-sum-val { color: #f1f5f9; }
    .hd-sum-total-val { font-size: 22px; font-weight: 800; color: #16a34a; }
    .hd-warn { font-size: 11px; font-weight: 700; color: #b45309; margin-left: 5px; }

    .hd-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .hd-table th {
        text-align: left; padding: 6px 8px; font-size: 10.5px; text-transform: uppercase;
        letter-spacing: .03em; color: #64748b; border-bottom: 1px solid #cbd5e1;
    }
    .dark .hd-table th { color: #94a3b8; border-color: #475569; }
    .hd-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; color: #1e293b; }
    .dark .hd-table td { border-color: #334155; color: #e2e8f0; }
    .hd-r { text-align: right; }
    .hd-center { text-align: center; }
    .hd-neg { color: #b91c1c; }
    .dark .hd-neg { color: #fca5a5; }
    .hd-x {
        background: none; border: 0; cursor: pointer; color: #b91c1c;
        font-size: 13px; padding: 4px 6px;
    }

    .hd-kind {
        display: inline-block; padding: 1px 7px; border-radius: 999px;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
    }
    .hd-kind-room { background: #e0e7ff; color: #3730a3; }
    .hd-kind-product { background: #dcfce7; color: #166534; }
    .hd-kind-service { background: #dbeafe; color: #1e40af; }
    .hd-kind-adjustment { background: #fef3c7; color: #92400e; }

    .hd-note {
        padding: 9px 12px; border-radius: 8px; font-size: 12.5px; margin-bottom: 11px;
        border: 1px solid #fcd34d; background: #fffbeb; color: #92400e;
    }
    .dark .hd-note { border-color: #b45309; background: #451a03; color: #fcd34d; }
    .hd-note-danger { border-color: #fca5a5; background: #fee2e2; color: #991b1b; }
    .dark .hd-note-danger { border-color: #991b1b; background: #450a0a; color: #fca5a5; }

    .hd-total-box {
        padding: 12px; border-radius: 10px; margin-bottom: 12px;
        border: 1px solid #e2e8f0; background: #f8fafc;
    }
    .dark .hd-total-box { border-color: #334155; background: #0f172a; }
    .hd-total-rows div {
        display: flex; justify-content: space-between; gap: 10px;
        font-size: 13px; padding: 3px 0; color: #334155;
    }
    .dark .hd-total-rows div { color: #e2e8f0; }
    .hd-total-big {
        display: flex; justify-content: space-between; align-items: baseline; gap: 10px;
        margin-top: 8px; padding-top: 8px; border-top: 2px solid #334155;
        font-size: 22px; font-weight: 800; color: #0f172a;
    }
    .dark .hd-total-big { border-color: #94a3b8; color: #f8fafc; }

    .hd-change {
        padding: 9px 11px; border-radius: 8px; text-align: right;
        font-size: 20px; font-weight: 800; min-height: 42px;
        border: 1px solid #86efac; background: #dcfce7; color: #166534;
    }
    .dark .hd-change { border-color: #166534; background: #052e16; color: #86efac; }
    .hd-change.is-short {
        border-color: #fca5a5; background: #fee2e2; color: #991b1b; font-size: 15px;
    }
    .dark .hd-change.is-short { border-color: #991b1b; background: #450a0a; color: #fca5a5; }

    .hd-muted { font-size: 11.5px; color: #64748b; }
    .dark .hd-muted { color: #94a3b8; }
    .hd-pad { padding: 7px 2px; }

    /* ---------------------------------------------------------------- */
    /*  Teléfono.
        El recepcionista también atiende desde el celular cuando el hotel es
        pequeño y la recepción es un mostrador. Las rejillas de 3 y 4 columnas
        se vuelven de 1 y 2: tres campos en 360px de ancho no se pueden tocar. */
    /* ---------------------------------------------------------------- */
    @media (max-width: 640px) {
        .hd-bar { gap: 10px; }
        .hd-search { flex-basis: 100%; }
        .hd-kpis { width: 100%; }
        .hd-kpi { flex: 1 1 0; min-width: 0; }

        .hd-grid { grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); }

        .hd-cols-3, .hd-cols-4 { grid-template-columns: repeat(2, 1fr); }
        .hd-span-2 { grid-column: span 2; }

        .hd-overlay { padding: 0; }
        .hd-modal { max-width: none; border-radius: 0; min-height: 100vh; }
        .hd-modal-body { max-height: none; }
        .hd-modal-foot { position: sticky; bottom: 0; flex-direction: column; }
        .hd-btn { width: 100%; }

        /* 16px: por debajo de eso iOS hace zoom al enfocar el campo y la
           pantalla queda descuadrada a mitad de un cobro. */
        .hd-input { font-size: 16px; }
        .hd-input-big { font-size: 22px; }

        .hd-table { font-size: 11.5px; }
        .hd-table th:nth-child(4), .hd-table td:nth-child(4) { display: none; }
    }
</style>
