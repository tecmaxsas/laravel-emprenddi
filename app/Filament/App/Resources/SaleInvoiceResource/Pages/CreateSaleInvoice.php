<?php

namespace App\Filament\App\Resources\SaleInvoiceResource\Pages;

use App\Filament\App\Resources\SaleInvoiceResource;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Services\Sales\DocumentNumberer;
use App\Services\Sales\SaleInvoiceEngine;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateSaleInvoice extends CreateRecord
{
    protected static string $resource = SaleInvoiceResource::class;

    /**
     * Vista propia solo para avisar antes de salir.
     *
     * Perder una factura de veinte lineas a medio digitar por un clic en el menu
     * es de las cosas que mas tiempo cuestan, y no hay forma de recuperarla.
     */
    protected static string $view = 'filament.app.pages.create-sale-invoice';

    /**
     * La factura nace en borrador, asi que «Crear» ya es guardar el progreso.
     * Se renombra porque el boton no lo decia: quien no lo sabe cree que crear
     * la factura es emitirla, y prefiere no tocarla hasta tenerla completa.
     */
    protected function getCreateFormAction(): Actions\Action
    {
        return parent::getCreateFormAction()
            ->label('Guardar borrador')
            ->icon('heroicon-o-bookmark-square');
    }

    protected function getCreateAnotherFormAction(): Actions\Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Guardar y crear otra');
    }

    protected function getSubmitFormAction(): Actions\Action
    {
        return $this->getCreateFormAction();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Borrador guardado';
    }

    /**
     * Preguntar antes de salir con la factura a medio armar.
     *
     * Es el guardian de Filament, no uno propio: compara un hash de los datos
     * del formulario contra el que se guardo al montar la pantalla, asi que
     * sabe de verdad si algo cambio. Una version casera que marca «sucio» con
     * cualquier tecla pregunta tambien cuando el usuario escribio y borro.
     *
     * Se activa solo aqui y no en todo el panel: una factura de veinte lineas
     * es lo que duele perder, no un formulario de tres campos.
     */
    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $companyId = (int) Auth::user()->company_id;
        $data['company_id'] = $companyId;
        $data['created_by_user_id'] = Auth::id();
        $data['status'] = 'draft';
        $data['payment_status'] = 'pendiente';

        // Blindaje multitenancy: la sede seleccionada debe ser de la misma
        // empresa del usuario. Sin esto, un superadmin o un request manipulado
        // podria emitir factura con la resolucion de otra empresa.
        $locationId = (int) ($data['location_id'] ?? 0);
        $locationOwnedByCompany = Location::query()
            ->where('id', $locationId)
            ->where('company_id', $companyId)
            ->exists();
        if (! $locationOwnedByCompany) {
            throw ValidationException::withMessages([
                'location_id' => 'La sede seleccionada no pertenece a tu empresa.',
            ]);
        }

        // El consecutivo sale de la resolución elegida a mano, si la hay, y si
        // no de la que tenga asignada la sede. Elegirla es lo excepcional: una
        // empresa con varias resoluciones vigentes a veces necesita emitir con
        // una concreta sin reasignarla.
        $numerador = app(DocumentNumberer::class);
        $resolucionElegida = (int) ($data['dian_resolution_id'] ?? 0);

        $doc = $resolucionElegida > 0
            ? $numerador->reserveForResolution($resolucionElegida, $companyId, $locationId)
            : $numerador->reserveForLocation($locationId, $data['invoice_kind'] ?? 'electronic');
        $data['prefix'] = $doc['prefix'];
        $data['number'] = $doc['number'];
        // El tipo lo decide la resolucion y no el selector: si alguien elige
        // una resolucion POS con «Electronica» marcado, la factura es POS. Al
        // reves se enviaria a la DIAN un consecutivo que no le corresponde.
        $data['invoice_kind'] = $doc['kind'];
        $data['dian_resolution_id'] = $doc['resolution_id'];

        // Nacio aqui, en el formulario. Y es distinto de `invoice_kind`: desde
        // aqui tambien se emiten documentos tipo POS, y eso no las convierte
        // en ventas del POS. De esta marca depende a que pantalla se vuelve
        // cuando haya que corregirla.
        $data['origin'] = SaleInvoice::ORIGIN_FORM;

        // Los totales NO se calculan aqui.
        //
        // El repetidor de lineas usa ->relationship('lines'), y Filament saca
        // esos campos del arreglo antes de guardar para persistirlos aparte.
        // O sea que `$data['lines']` llega VACIO: la suma recorria la nada y
        // escribia ceros. La factura quedaba guardada en $0 con sus lineas
        // intactas, y al contabilizarla generaba un asiento de cero.
        //
        // Se recalculan despues, desde las lineas ya guardadas, con el mismo
        // metodo del motor que usa post(). Una sola version de la suma.

        return $data;
    }

    protected function afterCreate(): void
    {
        app(SaleInvoiceEngine::class)->recalculateTotals($this->record);
    }
}
