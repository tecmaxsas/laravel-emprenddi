<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OrderTaking\Order;
use App\Models\User;
use App\Support\CurrentCompany;
use Tests\TestCase;

/**
 * El PDF del pedido, con el formato que el cliente ya usa.
 *
 * No es una preferencia estética: ese papel lleva años circulando entre el
 * vendedor y los compradores de Mas Dulces. Un documento «mejorado» que no se
 * parezca al de siempre obliga a volver a explicarlo en cada pedido, y el
 * comprador que busca «COSTO PEDIDO» no lo encuentra.
 *
 * Por eso lo que se prueba son los rótulos: si alguien renombra una columna
 * «para que se entienda mejor», deja de parecerse al original.
 *
 * Usa la base de desarrollo.
 */
class OrderTakingPdfTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);
    }

    /** Los rótulos son los del formato original, literales. */
    public function test_conserva_los_rotulos_del_formato_original(): void
    {
        $vista = file_get_contents(resource_path('views/order-taking/order-pdf.blade.php'));

        $rotulos = [
            'PEDIDO DE VENTA',
            'Facturar a:',
            'HORARIO RECIBO',
            'FORMA DE PAGO',
            'DESCRIPCION PRODUCTO',
            'PEDIDO<br>CAJAS',
            'VALOR CAJA',
            'IMPUESTO',
            'COSTO PEDIDO',
            'TOTAL CAJAS',
            'SUBTOTAL',
            'VALOR ANTES IMPUESTOS',
            'VALOR NETO FACTURA',
            'ENVIAR',
        ];

        foreach ($rotulos as $rotulo) {
            $this->assertStringContainsString($rotulo, $vista,
                "Falta «{$rotulo}», que en el formato del cliente sí está.");
        }
    }

    /**
     * El impuesto de la columna es el de UNA caja.
     *
     * `tax_amount` guarda el impuesto de la línea entera. Imprimirlo tal cual
     * en una columna que dice «IMPUESTO» junto a «VALOR CAJA» multiplicaría la
     * cifra por el número de cajas, y el comprador la compara con su propio
     * cálculo.
     */
    public function test_el_impuesto_se_muestra_por_caja(): void
    {
        $vista = file_get_contents(resource_path('views/order-taking/order-pdf.blade.php'));

        $this->assertStringContainsString('((float) $item->tax_amount) / $cajas', $vista,
            'Sin dividir, la columna muestra el impuesto de toda la línea.');
    }

    /**
     * Una fila sin pedido conserva sus precios de catálogo.
     *
     * El formato se manda con el catálogo completo y el comprador escribe
     * cantidades solo donde necesita. Si el precio con impuesto se calculara
     * dividiendo el impuesto de la línea, esas filas saldrían en cero y el
     * papel dejaría de servir para pedir — que es justamente para lo que se
     * manda.
     */
    public function test_una_fila_sin_pedido_conserva_sus_precios(): void
    {
        $vista = file_get_contents(resource_path('views/order-taking/order-pdf.blade.php'));

        $this->assertStringContainsString(
            '$valorCajaTotal = (float) $item->unit_price_at_public;',
            $vista,
            'El precio con impuesto sale del catálogo, no de dividir el impuesto de la línea.',
        );
    }

    /**
     * El costo del renglón sale del total guardado.
     *
     * No de multiplicar las columnas de arriba: si hubo redondeo, manda lo que
     * de verdad se cobra, no lo que den las cifras por caja.
     */
    public function test_el_costo_del_renglon_sale_del_total_guardado(): void
    {
        $vista = file_get_contents(resource_path('views/order-taking/order-pdf.blade.php'));

        $this->assertStringContainsString('$costoPedido = (float) $item->total;', $vista);
    }

    /** El PDF se genera y sale un PDF de verdad. */
    public function test_el_pdf_se_genera(): void
    {
        $pedido = Order::query()->where('company_id', $this->company->id)->latest('id')->first();

        if (! $pedido) {
            $this->markTestSkipped('No hay pedidos de toma pedidos en la base de desarrollo.');
        }

        $respuesta = $this->get(route('order-taking.orders.pdf', ['order' => $pedido->id]));

        $respuesta->assertOk();

        $this->assertStringStartsWith('%PDF', $respuesta->streamedContent(),
            'Si no empieza por %PDF, lo que se descargó no es un PDF.');
    }

    /** Un pedido de otra empresa no se puede descargar. */
    public function test_no_se_descarga_el_pedido_de_otra_empresa(): void
    {
        $ajeno = Order::query()
            ->withoutGlobalScopes()
            ->where('company_id', '!=', $this->company->id)
            ->first();

        if (! $ajeno) {
            $this->markTestSkipped('No hay pedidos de otra empresa en la base de desarrollo.');
        }

        $this->get(route('order-taking.orders.pdf', ['order' => $ajeno->id]))
            ->assertStatus(404);
    }
}
