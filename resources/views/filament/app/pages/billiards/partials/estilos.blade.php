{{-- Estilos del terminal de billares.

     EN UN ARCHIVO APARTE porque el blade del terminal ya es largo y el CSS
     se consulta solo cuando algo se ve mal: tenerlo abajo del todo obligaba
     a saltar mil líneas cada vez.

     DOS REGLAS QUE NO SE PUEDEN ROMPER, las dos aprendidas en el terminal de
     parqueadero y pagadas con defectos en producción:

     1. `background-color`, NUNCA la forma corta `background`, en cualquier
        regla que toque un input, un select o un textarea. La forma corta
        resetea también background-image, -repeat, -position y -size, y como
        la flecha del select se pinta con background-image, usarla la borra o
        la deja repetida según qué regla gane el orden. Así salieron las tres
        flechas apiladas.

     2. Todo campo lleva su fondo Y su color explícitos. Si falta uno, en
        modo oscuro el tema le pone texto blanco sobre fondo blanco y el
        cajero digita sin ver lo que escribe.

     Hay una prueba que vigila la primera (BilliardTerminalEstilosTest). --}}
<style>
    .bt-bar {
        display:grid; grid-template-columns:1fr auto auto; gap:14px; align-items:end;
        background-color:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px;
        margin-bottom:14px;
    }
    .bt-bar label { display:block; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; font-weight:700; margin-bottom:4px; }
    .bt-hall-tabs { display:flex; gap:6px; flex-wrap:wrap; }
    .bt-hall-tab { padding:7px 14px; border-radius:8px; background-color:#f1f5f9; color:#475569; border:1px solid #e2e8f0; font-weight:600; font-size:13px; cursor:pointer; }
    .bt-hall-tab:hover { background-color:#e2e8f0; }
    .bt-hall-tab.is-active { background-color:#6366f1; color:#fff; border-color:#6366f1; }

    .bt-kpis { display:flex; gap:6px; }
    .bt-kpi { display:flex; flex-direction:column; align-items:center; padding:7px 12px; border-radius:8px; min-width:62px; }
    .bt-kpi-num { font-size:18px; font-weight:900; }
    .bt-kpi-label { font-size:10px; text-transform:uppercase; font-weight:700; opacity:.85; }
    .bt-kpi-green { background-color:#dcfce7; color:#166534; }
    .bt-kpi-red { background-color:#fee2e2; color:#991b1b; }
    .bt-kpi-gray { background-color:#f1f5f9; color:#1e293b; }

    .bt-zone { background-color:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; margin-bottom:12px; }
    .bt-zone-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
    .bt-zone-head h3 { margin:0; font-size:15px; font-weight:800; color:#111827; }
    .bt-zone-count { font-size:11px; color:#6b7280; }

    .bt-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(132px, 1fr)); gap:8px; }
    .bt-tile {
        border:0; border-radius:10px; padding:10px 9px; color:#fff; cursor:pointer;
        display:flex; flex-direction:column; gap:3px; text-align:left; min-height:96px;
        box-shadow:0 1px 2px rgba(0,0,0,.12);
    }
    .bt-tile:hover { filter:brightness(1.08); }
    .bt-tile-disabled { cursor:not-allowed; opacity:.55; }
    .bt-tile-code { font-size:17px; font-weight:900; letter-spacing:.5px; display:flex; justify-content:space-between; align-items:center; gap:4px; }
    .bt-tile-type { font-size:10.5px; opacity:.85; font-weight:600; }
    .bt-tile-clock { font-size:15px; font-weight:800; font-family:ui-monospace, monospace; margin-top:auto; }
    .bt-tile-label { font-size:10.5px; opacity:.9; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .bt-tile-total { font-size:12px; font-weight:800; opacity:.95; }
    .bt-tile-paused { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }

    .bt-empty { background-color:#fff; border:1px dashed #cbd5e1; border-radius:12px; padding:28px; text-align:center; color:#64748b; font-size:14px; }

    /* ---------------------------------------------------------------- */
    /*  Modales                                                          */
    /* ---------------------------------------------------------------- */

    .bt-overlay { position:fixed; inset:0; background-color:rgba(15,23,42,.55); display:flex; align-items:center; justify-content:center; z-index:99; padding:14px; }
    .bt-modal { background-color:#fff; border-radius:14px; max-width:560px; width:100%; max-height:92vh; overflow:auto; box-shadow:0 30px 60px -20px rgba(0,0,0,.4); animation:btUp .22s ease both; }
    .bt-modal-lg { max-width:760px; }
    @keyframes btUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }

    .bt-modal-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; color:#fff; }
    .bt-modal-head-green { background-color:#16a34a; }
    .bt-modal-head-blue { background-color:#4f46e5; }
    .bt-modal-head-amber { background-color:#b45309; }
    .bt-modal-head h2 { margin:0; font-size:16px; font-weight:800; }
    .bt-modal-sub { font-size:11.5px; opacity:.85; margin-top:2px; }
    .bt-modal-close { background-color:transparent; color:#fff; border:0; font-size:22px; cursor:pointer; opacity:.85; }
    .bt-modal-close:hover { opacity:1; }
    .bt-modal-body { padding:18px; }
    .bt-modal-foot { padding:13px 18px; background-color:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }

    .bt-field { margin-bottom:14px; }
    .bt-field label { display:block; font-size:11px; text-transform:uppercase; color:#6b7280; font-weight:700; margin-bottom:5px; }
    /* TODOS los campos, no `input[type=text]`: un selector por tipo deja
       fuera los tipos que nadie recuerda al escribirlo, y esos son los que
       aparecen en blanco sobre blanco en modo oscuro. */
    .bt-field input, .bt-field textarea, .bt-field select {
        width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:7px; font-size:14px; font-family:inherit;
        background-color:#fff !important; color:#0f172a !important;
    }
    .bt-field input::placeholder, .bt-field textarea::placeholder { color:#94a3b8 !important; }
    .bt-field input:focus, .bt-field textarea:focus, .bt-field select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.15); }
    .bt-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }

    /* UNA flecha, y una sola. Dos clases a propósito: `.bt-modal select` a
       secas EMPATA en especificidad con `.fi-page select` del parcial
       compartido y ganaría solo por ir después en el documento, que es lo
       que se rompe el día que alguien mueve un bloque. */
    .bt-overlay .bt-modal select {
        appearance:none !important; -webkit-appearance:none !important; -moz-appearance:none !important;
        background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E") !important;
        background-repeat:no-repeat !important;
        background-position:right 10px center !important;
        background-size:16px 16px !important;
        padding-right:34px !important;
        text-overflow:ellipsis;
    }

    /* Red de seguridad: sin !important, para que las reglas de arriba y los
       estilos en línea sigan mandando. Que un campo se vea no puede depender
       de que quien lo escriba se acuerde de darle fondo y color. */
    .bt-modal input, .bt-modal textarea, .bt-modal select {
        background-color:#fff; color:#0f172a;
    }

    .bt-btn { padding:10px 16px; border:0; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; }
    .bt-btn-primary { background-color:#4f46e5; color:#fff; }
    .bt-btn-success { background-color:#16a34a; color:#fff; }
    .bt-btn-warning { background-color:#b45309; color:#fff; }
    .bt-btn-danger { background-color:#b91c1c; color:#fff; }
    .bt-btn-ghost { background-color:#e2e8f0; color:#334155; }
    .bt-btn:disabled { opacity:.5; cursor:not-allowed; }

    /* ---------------------------------------------------------------- */
    /*  La cuenta                                                        */
    /* ---------------------------------------------------------------- */

    .bt-clock-box { background-color:#0f172a; color:#fff; border-radius:10px; padding:14px 16px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .bt-clock-big { font-size:30px; font-weight:900; font-family:ui-monospace, monospace; line-height:1; }
    .bt-clock-cap { font-size:10.5px; text-transform:uppercase; letter-spacing:.07em; opacity:.7; font-weight:700; }
    .bt-clock-paused { background-color:#b45309; }

    .bt-lines { border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; margin-bottom:14px; }
    .bt-line { display:flex; align-items:center; gap:8px; padding:9px 12px; border-bottom:1px solid #f1f5f9; font-size:13px; }
    .bt-line:last-child { border-bottom:0; }
    .bt-line-time { background-color:#eef2ff; }
    .bt-line-adj { background-color:#fef3c7; }
    .bt-line-desc { flex:1; min-width:0; }
    .bt-line-desc strong { display:block; font-weight:700; color:#0f172a; }
    .bt-line-meta { font-size:11px; color:#64748b; }
    .bt-line-amount { font-weight:800; font-variant-numeric:tabular-nums; }
    .bt-line-btn { background-color:transparent; border:0; cursor:pointer; font-size:15px; line-height:1; padding:2px 5px; color:#64748b; }
    .bt-line-btn:hover { color:#0f172a; }

    .bt-search-results { border:1px solid #e2e8f0; border-radius:8px; margin-top:6px; max-height:230px; overflow:auto; }
    .bt-search-row { width:100%; display:flex; justify-content:space-between; gap:10px; padding:9px 12px; background-color:#fff; border:0; border-bottom:1px solid #f1f5f9; cursor:pointer; font-size:13px; text-align:left; color:#0f172a; }
    .bt-search-row:hover { background-color:#eef2ff; }
    .bt-search-code { font-size:11px; color:#64748b; margin-left:6px; }
    .bt-search-empty { font-size:12px; color:#64748b; padding:8px 2px; }

    .bt-totals { background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; }
    .bt-total-row { display:flex; justify-content:space-between; font-size:13px; padding:3px 0; color:#334155; }
    .bt-total-grand { display:flex; justify-content:space-between; font-size:19px; font-weight:900; color:#0f172a; border-top:1px dashed #cbd5e1; margin-top:8px; padding-top:8px; }

    .bt-breakdown { font-size:11.5px; color:#475569; background-color:#f1f5f9; border-radius:8px; padding:9px 12px; margin-bottom:12px; }
    .bt-breakdown div { display:flex; justify-content:space-between; gap:10px; padding:1px 0; }

    .bt-actions-row { display:flex; gap:7px; flex-wrap:wrap; margin-bottom:14px; }

    @media (max-width: 860px) {
        .bt-bar { grid-template-columns:1fr; }
        .bt-grid-2 { grid-template-columns:1fr; }
    }
</style>
