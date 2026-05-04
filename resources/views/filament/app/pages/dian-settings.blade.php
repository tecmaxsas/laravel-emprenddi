<x-filament-panels::page>
    <form wire:submit="saveTab1">
        {{ $this->form }}

        <div class="mt-6 flex justify-end gap-3">
            <x-filament::button type="submit" color="primary" icon="heroicon-o-cloud-arrow-up">
                Guardar y registrar en DIAN
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
