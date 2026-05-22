<x-filament-panels::page>
    <form wire:submit.prevent="save">
        {{ $this->form }}

        <div style="margin-top:16px; display:flex; justify-content:flex-end;">
            <x-filament::button type="submit">
                Guardar cuentas
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
