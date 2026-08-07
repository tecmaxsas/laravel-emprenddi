<x-filament-panels::page>
    <div style="background:#eef2ff; border:1px solid #6366f1; border-radius:10px; padding:14px 16px; margin-bottom:14px;">
        <div style="font-weight:800; color:#3730a3; font-size:14px;">📋 Cómo importar</div>
        <div style="font-size:12.5px; color:#4c1d95; margin-top:4px;">
            Sube los 2 archivos Excel con la estructura MAC DULCES original. Al importar se crearán:<br>
            · <strong>Productos</strong> de la lista (por código único)<br>
            · <strong>4 listas de precios</strong> (Lista 1 al 4) con sus items<br>
            · <strong>Clientes</strong> con la lista de precios asignada según la columna "COD LISTA NEW"<br>
            El proceso es idempotente: si un producto/cliente ya existe se <strong>actualiza</strong>, no se duplica.
        </div>
    </div>

    <form wire:submit.prevent>{{ $this->form }}</form>

    <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px; align-items:center;">
        <span wire:loading wire:target="confirmImport" style="color:#4c1d95; font-size:13px; font-weight:700;">
            ⏳ Procesando... no cierres esta pestaña
        </span>
        <button type="button" wire:click="confirmImport"
                wire:confirm="¿Confirmar importación? Esto crea/actualiza productos, precios y clientes."
                wire:loading.attr="disabled" wire:target="confirmImport"
                style="padding:12px 24px; background:#16a34a; color:#fff; border:0; border-radius:8px; font-weight:800; font-size:14px; cursor:pointer;">
            <span wire:loading.remove wire:target="confirmImport">✓ Ejecutar importación</span>
            <span wire:loading wire:target="confirmImport">Importando...</span>
        </button>
    </div>

    @if ($result)
        <div style="margin-top:14px; background:#f0fdf4; border:2px solid #16a34a; border-radius:10px; padding:14px 16px;">
            <div style="font-weight:800; color:#166534; font-size:14px; margin-bottom:8px;">✓ Importación completada</div>
            <table style="width:100%; font-size:12.5px; color:#166534;">
                <tr><td style="padding:3px 0;">Productos creados</td><td style="text-align:right; font-weight:800;">{{ $result['products_created'] }}</td></tr>
                <tr><td style="padding:3px 0;">Productos actualizados</td><td style="text-align:right; font-weight:800;">{{ $result['products_updated'] }}</td></tr>
                <tr><td style="padding:3px 0;">Listas de precios</td><td style="text-align:right; font-weight:800;">{{ $result['price_lists'] }}</td></tr>
                <tr><td style="padding:3px 0;">Items de precio</td><td style="text-align:right; font-weight:800;">{{ $result['price_items'] }}</td></tr>
                <tr><td style="padding:3px 0;">Clientes creados</td><td style="text-align:right; font-weight:800;">{{ $result['customers_created'] }}</td></tr>
                <tr><td style="padding:3px 0;">Clientes actualizados</td><td style="text-align:right; font-weight:800;">{{ $result['customers_updated'] }}</td></tr>
            </table>
        </div>
    @endif
</x-filament-panels::page>
