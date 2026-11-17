<?php

namespace App\Filament\Concerns;

use App\Models\ThirdParty;
use App\Services\Sales\QuickCustomer;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * El modal de «¿a nombre de quién va la factura?» de un terminal.
 *
 * Busca entre los terceros de la empresa y, si no está, lo crea ahí mismo:
 * nadie va a salir del cobro con el cliente al frente para ir a Terceros.
 *
 * Quien use el trait decide dónde guarda lo elegido —cada terminal tiene su
 * propio formulario de cobro— implementando `aplicarCliente()`. Es lo único
 * que cambia entre pantallas; todo lo demás es idéntico y por eso vive aquí
 * una sola vez.
 */
trait EligeCliente
{
    public bool $customerModalOpen = false;

    public string $customerSearch = '';

    public string $newCustomerName = '';

    public string $newCustomerDocumentType = 'cc';

    public string $newCustomerDocument = '';

    public string $newCustomerEmail = '';

    public string $newCustomerPhone = '';

    public string $newCustomerAddress = '';

    /** Guarda (o limpia, con null) el cliente elegido donde cada terminal lo lleve. */
    abstract protected function aplicarCliente(?ThirdParty $cliente): void;

    /** @return Collection<int, ThirdParty> */
    public function getCustomerMatchesProperty(): Collection
    {
        return app(QuickCustomer::class)->search(
            (int) auth()->user()?->company_id,
            $this->customerSearch,
        );
    }

    public function openCustomerModal(): void
    {
        $this->customerModalOpen = true;
    }

    public function closeCustomerModal(): void
    {
        $this->customerModalOpen = false;
        $this->customerSearch = '';
        $this->newCustomerName = '';
        $this->newCustomerDocumentType = 'cc';
        $this->newCustomerDocument = '';
        $this->newCustomerEmail = '';
        $this->newCustomerPhone = '';
        $this->newCustomerAddress = '';
    }

    public function selectCustomer(int $thirdPartyId): void
    {
        $cliente = ThirdParty::query()
            ->where('company_id', auth()->user()?->company_id)
            ->find($thirdPartyId);

        if (! $cliente) {
            Notification::make()->danger()->title('Ese cliente ya no existe')->send();

            return;
        }

        $this->aplicarCliente($cliente);
        $this->closeCustomerModal();
    }

    public function clearCustomer(): void
    {
        $this->aplicarCliente(null);
        $this->closeCustomerModal();
    }

    public function createCustomer(): void
    {
        try {
            $resultado = app(QuickCustomer::class)->create(
                (int) auth()->user()?->company_id,
                [
                    'name' => $this->newCustomerName,
                    'document_type' => $this->newCustomerDocumentType,
                    'document_number' => $this->newCustomerDocument,
                    'email' => $this->newCustomerEmail,
                    'phone' => $this->newCustomerPhone,
                    'address' => $this->newCustomerAddress,
                ],
            );
        } catch (\RuntimeException $e) {
            Notification::make()->danger()
                ->title('No se pudo crear el cliente')->body($e->getMessage())->send();

            return;
        }

        $cliente = $resultado['customer'];
        $this->aplicarCliente($cliente);
        $this->closeCustomerModal();

        $resultado['existed']
            ? Notification::make()->warning()
                ->title('Ese documento ya estaba registrado')
                ->body(trim("Se seleccionó {$cliente->name}. ".($resultado['note'] ?? '')))->send()
            : Notification::make()->success()
                ->title("Cliente {$cliente->name} creado")->send();
    }
}
