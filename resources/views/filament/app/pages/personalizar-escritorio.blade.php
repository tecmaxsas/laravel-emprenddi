<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div style="display:flex; justify-content:flex-end; margin-top:16px;">
            <x-filament::button type="submit">
                Guardar preferencias
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
