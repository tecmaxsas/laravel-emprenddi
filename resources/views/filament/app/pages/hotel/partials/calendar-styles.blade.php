{{--
    Estilos del calendario de disponibilidad.

    Aparte del parcial de la Recepción porque son dos rejillas distintas: la de
    allá son tarjetas que se tocan con el dedo, esta es una tabla densa que se
    lee de un vistazo. Mezclarlas obligaría a pelear con las mismas clases para
    dos cosas que no se parecen.

    Igual que en la Recepción, cada color está escrito en claro y en oscuro: un
    calendario donde no se distingue lo ocupado de lo libre no es un
    calendario.
--}}
<style>
    .hc-bar {
        display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px;
        padding: 12px 14px; margin-bottom: 12px;
        border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc;
    }
    .dark .hc-bar { border-color: #334155; background: #1e293b; }

    .hc-field label {
        display: block; font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .03em; color: #64748b; margin-bottom: 4px;
    }
    .dark .hc-field label { color: #94a3b8; }

    .hc-input {
        padding: 8px 10px; font-size: 13.5px; min-height: 40px;
        border: 1px solid #cbd5e1; border-radius: 8px;
        background: #fff; color: #0f172a;
    }
    .dark .hc-input { background: #0f172a; border-color: #475569; color: #f1f5f9; }
    .hc-input-big { font-size: 19px; font-weight: 700; text-align: right; width: 100%; }

    .hc-tabs { display: flex; flex-wrap: wrap; gap: 6px; }
    .hc-tab {
        padding: 7px 13px; border-radius: 8px; border: 1px solid #cbd5e1;
        background: #fff; color: #334155; font-weight: 600; font-size: 13px; cursor: pointer;
    }
    .dark .hc-tab { background: #0f172a; border-color: #475569; color: #e2e8f0; }
    .hc-tab.is-active { background: #4f46e5; border-color: #4f46e5; color: #fff; }

    .hc-nav { display: flex; gap: 6px; margin-left: auto; }

    .hc-btn {
        display: inline-flex; align-items: center; justify-content: center;
        padding: 9px 15px; border: 0; border-radius: 8px; cursor: pointer;
        font-weight: 700; font-size: 13px; min-height: 40px;
    }
    .hc-btn-gray { background: #e2e8f0; color: #334155; }
    .dark .hc-btn-gray { background: #334155; color: #e2e8f0; }
    .hc-btn-indigo { background: #4f46e5; color: #fff; }

    /* ---------------------------------------------------------------- */
    /*  La rejilla */
    /* ---------------------------------------------------------------- */
    .hc-wrap {
        overflow-x: auto;
        border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;
    }
    .dark .hc-wrap { border-color: #334155; background: #1e293b; }

    .hc-grid { border-collapse: collapse; width: 100%; font-size: 12px; }

    /* La columna de habitaciones se queda quieta al desplazar los días:
       sin esto, a treinta días uno ya no sabe qué fila está mirando. */
    .hc-room, .hc-room-head {
        position: sticky; left: 0; z-index: 2;
        background: #f8fafc; text-align: left;
        padding: 5px 10px; min-width: 112px;
        border-right: 2px solid #cbd5e1; border-bottom: 1px solid #f1f5f9;
    }
    .dark .hc-room, .dark .hc-room-head { background: #0f172a; border-right-color: #475569; border-bottom-color: #334155; }

    .hc-room-head {
        font-size: 10.5px; text-transform: uppercase; letter-spacing: .03em;
        color: #64748b; z-index: 3;
    }
    .hc-room-num { display: block; font-weight: 800; font-size: 13px; color: #1e293b; font-family: ui-monospace, monospace; }
    .dark .hc-room-num { color: #f1f5f9; }
    .hc-room-type { display: block; font-size: 10px; color: #94a3b8; }

    .hc-day {
        padding: 4px 2px; min-width: 34px; text-align: center;
        border-bottom: 2px solid #cbd5e1; background: #f8fafc; color: #475569;
    }
    .dark .hc-day { background: #0f172a; border-bottom-color: #475569; color: #94a3b8; }
    .hc-day.is-weekend { background: #eef2ff; }
    .dark .hc-day.is-weekend { background: #1e1b4b; }
    .hc-day.is-today { background: #4f46e5; color: #fff; }

    .hc-day-name { display: block; font-size: 9px; text-transform: uppercase; opacity: .75; }
    .hc-day-num { display: block; font-size: 14px; font-weight: 800; line-height: 1.1; }
    .hc-day-mon { display: block; font-size: 9px; opacity: .6; }

    .hc-zone-row td {
        padding: 3px 10px; font-size: 10.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .04em;
        color: #64748b; background: #f1f5f9;
        position: sticky; left: 0;
    }
    .dark .hc-zone-row td { color: #94a3b8; background: #0f172a; }

    .hc-cell {
        height: 30px; min-width: 34px; padding: 0 2px;
        border-bottom: 1px solid #f1f5f9; border-right: 1px solid #f8fafc;
        position: relative; white-space: nowrap; overflow: visible;
    }
    .dark .hc-cell { border-bottom-color: #334155; border-right-color: #1e293b; }
    .hc-cell.is-today { box-shadow: inset 2px 0 0 #4f46e5, inset -2px 0 0 #4f46e5; }

    /* Lo que ocupa. El texto va dentro de la barra, escrito una sola vez
       al principio, para que no se repita en cada casilla. */
    .hc-estadia { background: #dc2626; }
    .hc-reserva { background: #f59e0b; }
    .hc-cerrada { background: #e2e8f0; }
    .dark .hc-cerrada { background: #334155; }
    .hc-bloqueo {
        background: repeating-linear-gradient(45deg, #94a3b8, #94a3b8 4px, #cbd5e1 4px, #cbd5e1 8px);
    }
    .dark .hc-bloqueo {
        background: repeating-linear-gradient(45deg, #475569, #475569 4px, #334155 4px, #334155 8px);
    }

    .hc-cell.is-free { cursor: pointer; }
    .hc-cell.is-free:hover { background: #dcfce7; box-shadow: inset 0 0 0 2px #16a34a; }
    .dark .hc-cell.is-free:hover { background: #052e16; }

    .hc-label {
        position: absolute; left: 4px; top: 50%; transform: translateY(-50%);
        font-size: 10.5px; font-weight: 700; color: #fff;
        text-shadow: 0 1px 2px rgba(0, 0, 0, .35);
        pointer-events: none; z-index: 1;
    }
    .hc-cerrada .hc-label { color: #475569; text-shadow: none; }
    .dark .hc-cerrada .hc-label { color: #cbd5e1; }
    .hc-bloqueo .hc-label { color: #1e293b; text-shadow: none; }

    .hc-total {
        padding: 4px 2px; text-align: center;
        background: #f1f5f9; border-top: 2px solid #cbd5e1;
    }
    .dark .hc-total { background: #0f172a; border-top-color: #475569; }
    .hc-total-num { display: block; font-size: 14px; font-weight: 800; color: #16a34a; }
    .hc-total-pct { display: block; font-size: 9px; color: #94a3b8; }
    .hc-total.is-full .hc-total-num { color: #dc2626; }
    .hc-total.is-today { box-shadow: inset 2px 0 0 #4f46e5, inset -2px 0 0 #4f46e5; }

    .hc-legend {
        display: flex; flex-wrap: wrap; gap: 14px; margin-top: 10px;
        font-size: 11.5px; color: #64748b;
    }
    .dark .hc-legend { color: #94a3b8; }
    .hc-legend span { display: inline-flex; align-items: center; gap: 5px; }
    .hc-legend i { width: 14px; height: 14px; border-radius: 3px; display: inline-block; }
    .hc-legend .hc-libre-i { border: 1px solid #cbd5e1; background: #fff; }
    .dark .hc-legend .hc-libre-i { border-color: #475569; background: #1e293b; }

    .hc-empty {
        padding: 28px; text-align: center; font-size: 13.5px; color: #64748b;
        border: 1px dashed #cbd5e1; border-radius: 10px; background: #f8fafc;
    }
    .dark .hc-empty { color: #94a3b8; border-color: #475569; background: #1e293b; }

    /* ---------------------------------------------------------------- */
    /*  Modal de reserva rápida */
    /* ---------------------------------------------------------------- */
    .hc-overlay {
        position: fixed; inset: 0; z-index: 60; background: rgba(15, 23, 42, .6);
        display: flex; align-items: flex-start; justify-content: center;
        padding: 24px 12px; overflow-y: auto;
    }
    .hc-modal {
        width: 100%; max-width: 520px; background: #fff; border-radius: 12px;
        box-shadow: 0 20px 45px rgba(15, 23, 42, .35); overflow: hidden;
    }
    .dark .hc-modal { background: #1e293b; }

    .hc-modal-head {
        display: flex; justify-content: space-between; align-items: center; gap: 10px;
        padding: 12px 16px; background: #4f46e5; color: #fff;
    }
    .hc-modal-head h2 {
        margin: 0; font-size: 15.5px; font-weight: 700;
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    }
    .hc-modal-head code {
        background: rgba(255, 255, 255, .22); padding: 1px 7px; border-radius: 5px;
        font-family: ui-monospace, monospace;
    }
    .hc-sub { font-size: 12px; font-weight: 500; opacity: .85; }
    .hc-close {
        background: rgba(255, 255, 255, .2); border: 0; color: #fff;
        width: 30px; height: 30px; border-radius: 7px; cursor: pointer; font-size: 14px;
    }

    .hc-modal-body { padding: 14px 16px; max-height: 70vh; overflow-y: auto; }
    .hc-modal-body .hc-field { margin-bottom: 11px; }
    .hc-modal-body .hc-input { width: 100%; }

    .hc-modal-foot {
        display: flex; justify-content: flex-end; gap: 8px;
        padding: 11px 16px; border-top: 1px solid #e2e8f0; background: #f8fafc;
    }
    .dark .hc-modal-foot { border-color: #334155; background: #0f172a; }

    .hc-cols-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .hc-span-2 { grid-column: span 2; }

    .hc-hint { display: block; font-size: 11px; color: #94a3b8; margin-top: 3px; }

    .hc-quote {
        margin: 11px 0; padding: 10px 12px; border-radius: 9px;
        border: 1px solid #c7d2fe; background: #eef2ff;
    }
    .dark .hc-quote { border-color: #3730a3; background: #1e1b4b; }
    .hc-quote-head {
        display: flex; justify-content: space-between; align-items: baseline;
        font-size: 13px; color: #3730a3;
    }
    .dark .hc-quote-head { color: #c7d2fe; }
    .hc-quote-head strong { font-size: 19px; font-weight: 800; }
    .hc-quote-rows { margin-top: 7px; border-top: 1px solid #c7d2fe; padding-top: 6px; }
    .dark .hc-quote-rows { border-top-color: #3730a3; }
    .hc-quote-rows div {
        display: flex; justify-content: space-between; gap: 10px;
        font-size: 11.5px; padding: 2px 0; color: #4338ca;
    }
    .dark .hc-quote-rows div { color: #a5b4fc; }
    .hc-quote-rows em { font-style: normal; opacity: .75; margin-left: 5px; }
    .hc-quote-plain { margin-top: 5px; font-size: 11.5px; color: #4338ca; }
    .dark .hc-quote-plain { color: #a5b4fc; }

    @media (max-width: 640px) {
        .hc-bar { gap: 8px; }
        .hc-nav { margin-left: 0; width: 100%; }
        .hc-nav .hc-btn { flex: 1; }
        .hc-overlay { padding: 0; }
        .hc-modal { max-width: none; border-radius: 0; min-height: 100vh; }
        .hc-modal-foot { position: sticky; bottom: 0; }
        /* 16px evita que iOS haga zoom al enfocar. */
        .hc-input { font-size: 16px; }
        .hc-room, .hc-room-head { min-width: 86px; }
    }
</style>
