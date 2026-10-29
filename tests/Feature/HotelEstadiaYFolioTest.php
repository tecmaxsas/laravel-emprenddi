<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Hotel\Stay;
use App\Models\Hotel\StayCharge;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use App\Services\Hotel\HotelBillingEngine;
use App\Services\Hotel\HotelFolio;
use App\Services\Hotel\HotelProductProvisioner;
use App\Services\Hotel\HotelReservationEngine;
use App\Services\Hotel\HotelStayEngine;
use App\Support\CurrentCompany;
use App\Support\PaymentAccountResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MontaUnHotel;
use Tests\TestCase;

/**
 * La estadía y su folio: lo que el huésped debe cuando se va.
 *
 * Lo que se mide aquí es la cuenta, y la cuenta tiene dos formas de salir mal
 * que no se notan hasta que el huésped reclama:
 *
 *  - **Cobrar una noche que no durmió.** El pase nocturno carga las noches
 *    mientras el huésped está adentro; si el que se va antes de lo pactado se
 *    lleva esas noches cargadas, se le cobra de más.
 *  - **No cobrar una noche que sí durmió.** El que entrega la habitación a las
 *    cinco de la tarde usó otra noche, y si el sistema no lo suma el hotel la
 *    regala.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class HotelEstadiaYFolioTest extends TestCase
{
    use MontaUnHotel;

    private User $usuario;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->empresa = Company::findOrFail($this->usuario->company_id);

        $this->actingAs($this->usuario);
        app(CurrentCompany::class)->set($this->empresa);

        $this->sede = Location::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->orderBy('id')
            ->firstOrFail();

        $this->enciendeElModuloHotel($this->empresa, $this->limpiar);
        $this->daleResolucionPosALaSede();
        $this->hotel = $this->montaUnHotel($this->empresa, $this->sede, $this->limpiar, habitaciones: 2);
        $this->abreUnTurnoDeCaja($this->empresa, $this->sede, (int) $this->usuario->id, $this->limpiar);
    }

    protected function tearDown(): void
    {
        $this->limpiaLoCreado($this->limpiar);

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /*  Check-in */
    /* ------------------------------------------------------------------ */

    /** Alojar deja la habitación ocupada, el folio numerado y la primera noche cargada. */
    public function test_el_checkin_abre_el_folio_con_la_primera_noche(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);

        $estadia = $this->alojar($habitacion, noches: 2);

        $this->assertSame(Stay::STATUS_OPEN, $estadia->status);
        $this->assertSame(2, (int) $estadia->nights);
        $this->assertSame('H', $estadia->prefix);
        $this->assertGreaterThan(0, (int) $estadia->number);

        $this->assertSame(
            Room::STATUS_OCCUPIED,
            $habitacion->fresh()->status,
            'La habitación tiene que quedar ocupada.',
        );

        // Solo la noche de hoy: las futuras no se cobran por adelantado,
        // porque si el huésped se va antes habría que devolverle plata ya
        // facturada.
        $noches = StayCharge::query()->where('stay_id', $estadia->id)->noches()->count();
        $this->assertSame(1, $noches);
        $this->assertEqualsWithDelta(100000, (float) $estadia->fresh()->total, 0.01);
    }

    /** Sin tarifa no se aloja a nadie: el folio quedaría sin precio. */
    public function test_no_se_aloja_con_tarifa_en_cero(): void
    {
        $this->tipoDoble->update(['base_rate' => 0]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tarifa/i');

        app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'expected_check_out' => now()->addDay()->toDateString(),
        ]);
    }

    /** Una estadía de cero noches no existe. */
    public function test_la_salida_tiene_que_ser_despues_de_la_entrada(): void
    {
        $this->expectException(\RuntimeException::class);

        app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'expected_check_out' => now()->toDateString(),
        ]);
    }

    /** El check-in de una reserva la deja alojada y las dos quedan enlazadas. */
    public function test_el_checkin_de_una_reserva_las_enlaza(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);

        $reserva = app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $habitacion->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'guest_name' => 'Reservado ZZ',
            'rate_per_night' => 85000,
        ]);

        $estadia = app(HotelStayEngine::class)->checkInDeReserva($reserva);
        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        $this->assertSame((int) $reserva->id, (int) $estadia->reservation_id);
        $this->assertSame(Reservation::STATUS_CHECKED_IN, $reserva->fresh()->status);
        $this->assertSame((int) $estadia->id, (int) $reserva->fresh()->stay_id);

        // Lo pactado por teléfono es lo que se cobra, no la tarifa de la lista.
        $this->assertEqualsWithDelta(85000, (float) $estadia->rate_per_night, 0.01);
    }

    /** Una reserva no se aloja dos veces. */
    public function test_una_reserva_no_hace_checkin_dos_veces(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);

        $reserva = app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $habitacion->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
            'guest_name' => 'Doble checkin ZZ',
        ]);

        $estadia = app(HotelStayEngine::class)->checkInDeReserva($reserva);
        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        $this->expectException(\RuntimeException::class);

        app(HotelStayEngine::class)->checkInDeReserva($reserva->fresh());
    }

    /* ------------------------------------------------------------------ */
    /*  El pase nocturno */
    /* ------------------------------------------------------------------ */

    /**
     * Cargar las noches dos veces no cobra dos veces.
     *
     * Importa porque el folio se abre muchas veces al día y cada apertura
     * dispara el pase.
     */
    public function test_el_pase_nocturno_es_idempotente(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 3);
        $folio = app(HotelFolio::class);

        $folio->cargarNochesPendientes($estadia);
        $folio->cargarNochesPendientes($estadia);
        $folio->cargarNochesPendientes($estadia);

        $this->assertSame(
            1,
            StayCharge::query()->where('stay_id', $estadia->id)->noches()->count(),
            'Tres pases el mismo día tienen que dejar una sola noche cargada.',
        );
    }

    /**
     * El pase no cobra más allá de lo pactado.
     *
     * Si el huésped se queda de más, es el check-out el que decide cuántas
     * noches se cobran; el pase no puede irse solo sumando días.
     */
    public function test_el_pase_no_pasa_de_la_salida_pactada(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 2);

        // Se simula que ya pasó la fecha de salida.
        app(HotelFolio::class)->cargarNochesPendientes($estadia, now()->addDays(10));

        $this->assertSame(
            2,
            StayCharge::query()->where('stay_id', $estadia->id)->noches()->count(),
            'Pactó dos noches: el pase no puede cargar diez.',
        );
    }

    /**
     * Quien se va antes no paga la noche que no durmió.
     *
     * Este es el caso que descuadra una cuenta sin que nadie lo note: el pase
     * nocturno ya cargó la noche de hoy, el huésped se va esta mañana, y si
     * la liquidación no quita esa noche se le cobra una de más.
     */
    public function test_el_que_se_va_antes_no_paga_la_noche_que_no_durmio(): void
    {
        $entrada = now()->subDays(3);

        $estadia = $this->alojarEn($this->habitacion($this->hotel, 1), $entrada, $entrada->copy()->addDays(5));

        // El pase carga hasta hoy: 3 noches (la de hace 3 días, la de anteayer
        // y la de ayer) más la de hoy.
        app(HotelFolio::class)->cargarNochesPendientes($estadia);
        $cargadas = StayCharge::query()->where('stay_id', $estadia->id)->noches()->count();
        $this->assertSame(4, $cargadas, 'El pase debería haber cargado hasta la noche de hoy.');

        // Se va hoy a las 10 de la mañana, antes de la hora de salida.
        $resumen = app(HotelStayEngine::class)->liquidar(
            $estadia->fresh(),
            now()->copy()->setTime(10, 0),
        );

        $this->assertSame(3, $resumen['noches'], 'Durmió tres noches, no cuatro.');
        $this->assertFalse($resumen['noche_extra']);
        $this->assertSame(
            3,
            StayCharge::query()->where('stay_id', $estadia->id)->noches()->count(),
            'La noche de hoy, que no durmió, tiene que salir del folio.',
        );
        $this->assertEqualsWithDelta(300000, (float) $estadia->fresh()->total, 0.01);
    }

    /**
     * Quien entrega tarde paga la noche extra.
     *
     * Con la hora de salida en 12:00 y una hora de gracia, salir a las 17:00
     * usó otra noche. Sin esto el hotel la regala.
     */
    public function test_la_salida_tardia_suma_una_noche(): void
    {
        $entrada = now()->subDay();

        $estadia = $this->alojarEn($this->habitacion($this->hotel, 1), $entrada, $entrada->copy()->addDays(2));

        $resumen = app(HotelStayEngine::class)->liquidar(
            $estadia->fresh(),
            now()->copy()->setTime(17, 0),
        );

        $this->assertTrue($resumen['noche_extra'], 'Las 17:00 están muy pasadas de las 12:00.');
        $this->assertSame(2, $resumen['noches']);
    }

    /** Dentro de la hora de gracia no se cobra nada extra. */
    public function test_la_holgura_evita_la_pelea_en_el_mostrador(): void
    {
        $entrada = now()->subDay();

        $estadia = $this->alojarEn($this->habitacion($this->hotel, 1), $entrada, $entrada->copy()->addDays(2));

        // 12:30 con una hora de gracia: todavía no suma noche.
        $resumen = app(HotelStayEngine::class)->liquidar(
            $estadia->fresh(),
            now()->copy()->setTime(12, 30),
        );

        $this->assertFalse($resumen['noche_extra'], 'Media hora tarde no es una noche más.');
        $this->assertSame(1, $resumen['noches']);
    }

    /* ------------------------------------------------------------------ */
    /*  Consumos y ajustes */
    /* ------------------------------------------------------------------ */

    /** Un consumo entra con su IVA descompuesto desde el precio al público. */
    public function test_un_consumo_descompone_el_iva(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 1);
        $iva = $this->impuesto('ZZIVAH19', 'IVA 19 ZZH', 'vat', 19);

        $cargo = app(HotelFolio::class)->agregarConsumo($estadia, [
            'description' => 'Cerveza ZZ',
            'quantity' => 2,
            'unit_price' => 11900,
            'tax_id' => $iva->id,
        ]);

        // 2 × 11.900 = 23.800 al público → base 20.000, IVA 3.800.
        $this->assertEqualsWithDelta(23800, (float) $cargo->total, 0.01);
        $this->assertEqualsWithDelta(20000, (float) $cargo->subtotal, 0.01);
        $this->assertEqualsWithDelta(3800, (float) $cargo->tax_amount, 0.01);

        $this->assertEqualsWithDelta(23800, (float) $estadia->fresh()->consumption_total, 0.01);
    }

    /** Un cargo sin valor no se guarda: un renglón en cero confunde el folio. */
    public function test_un_consumo_en_cero_se_rechaza(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 1);

        $this->expectException(\RuntimeException::class);

        app(HotelFolio::class)->agregarConsumo($estadia, [
            'description' => 'Nada ZZ',
            'quantity' => 1,
            'unit_price' => 0,
        ]);
    }

    /** Un ajuste baja el total del folio y queda como rebaja, no como consumo. */
    public function test_un_ajuste_baja_el_folio(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 1);

        app(HotelFolio::class)->agregarAjuste($estadia, 'Aire dañado ZZ', 20000);

        $fresco = $estadia->fresh();

        $this->assertEqualsWithDelta(20000, (float) $fresco->discount_total, 0.01);
        $this->assertEqualsWithDelta(80000, (float) $fresco->total, 0.01);
        $this->assertEqualsWithDelta(0, (float) $fresco->consumption_total, 0.01);
    }

    /** Un ajuste sin concepto no se guarda: es lo que se le explica al huésped. */
    public function test_un_ajuste_exige_concepto(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 1);

        $this->expectException(\RuntimeException::class);

        app(HotelFolio::class)->agregarAjuste($estadia, '  ', 10000);
    }

    /**
     * Las noches no se quitan a mano desde el folio.
     *
     * Quitarla no sirve de nada —la liquidación la vuelve a cargar— y da la
     * falsa sensación de haber corregido algo.
     */
    public function test_un_consumo_se_puede_quitar(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 1);
        $folio = app(HotelFolio::class);

        $cargo = $folio->agregarConsumo($estadia, [
            'description' => 'Lavandería ZZ',
            'quantity' => 1,
            'unit_price' => 15000,
        ]);

        $this->assertEqualsWithDelta(115000, (float) $estadia->fresh()->total, 0.01);

        $folio->quitar($cargo);

        $this->assertEqualsWithDelta(100000, (float) $estadia->fresh()->total, 0.01);
        $this->assertEqualsWithDelta(0, (float) $estadia->fresh()->consumption_total, 0.01);
    }

    /* ------------------------------------------------------------------ */
    /*  Exención de IVA del turista extranjero */
    /* ------------------------------------------------------------------ */

    /**
     * La exención del art. 481 ET cubre el hospedaje, no el minibar.
     *
     * Aplicarla a todo le dejaría de cobrar al huésped un IVA que el hotel sí
     * tiene que declarar por los consumos.
     */
    public function test_la_exencion_cubre_el_alojamiento_pero_no_los_consumos(): void
    {
        $iva = $this->impuesto('ZZIVAH19', 'IVA 19 ZZH', 'vat', 19);

        $estadia = app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $this->habitacion($this->hotel, 1)->id,
            'expected_check_out' => now()->addDay()->toDateString(),
            'tax_exempt' => true,
        ]);
        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        $this->assertTrue((bool) $estadia->tax_exempt);
        $this->assertNotNull($estadia->tax_exempt_reason, 'La exención hay que poder sustentarla.');

        $noche = StayCharge::query()->where('stay_id', $estadia->id)->noches()->firstOrFail();
        $this->assertEqualsWithDelta(0, (float) $noche->tax_amount, 0.01, 'El alojamiento va exento.');
        $this->assertEqualsWithDelta(100000, (float) $noche->subtotal, 0.01);

        $consumo = app(HotelFolio::class)->agregarConsumo($estadia, [
            'description' => 'Cerveza ZZ',
            'quantity' => 1,
            'unit_price' => 11900,
            'tax_id' => $iva->id,
        ]);

        $this->assertEqualsWithDelta(1900, (float) $consumo->tax_amount, 0.01, 'El minibar sí se grava.');
    }

    /* ------------------------------------------------------------------ */
    /*  Traslado y extensión */
    /* ------------------------------------------------------------------ */

    /** El folio es de la persona, no de la habitación. */
    public function test_el_traslado_conserva_el_folio(): void
    {
        $uno = $this->habitacion($this->hotel, 1);
        $dos = $this->habitacion($this->hotel, 2);

        $estadia = $this->alojar($uno, noches: 3);
        $totalAntes = (float) $estadia->fresh()->total;

        $trasladada = app(HotelStayEngine::class)->cambiarHabitacion($estadia->fresh(), (int) $dos->id, 'Ruido ZZ');

        $this->assertSame((int) $dos->id, (int) $trasladada->room_id);
        $this->assertEqualsWithDelta($totalAntes, (float) $trasladada->total, 0.01);

        // La que deja queda para aseo, no disponible: hay que tenderla.
        $this->assertSame(Room::STATUS_CLEANING, $uno->fresh()->status);
        $this->assertSame(Room::STATUS_OCCUPIED, $dos->fresh()->status);
    }

    /** Extender comprueba que la habitación siga libre. */
    public function test_extender_choca_con_la_reserva_siguiente(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);

        $estadia = $this->alojar($habitacion, noches: 1);

        // Alguien ya tiene esa habitación a partir de mañana.
        app(HotelReservationEngine::class)->crear([
            'hotel_id' => $this->hotel->id,
            'room_id' => $habitacion->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(4)->toDateString(),
            'guest_name' => 'El que viene ZZ',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no está disponible/i');

        app(HotelStayEngine::class)->cambiarFechaDeSalida($estadia->fresh(), now()->addDays(3)->toDateString());
    }

    /** Extender cuando sí cabe carga las noches nuevas al abrir el folio. */
    public function test_extender_cuando_cabe_actualiza_las_noches(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 1);

        $extendida = app(HotelStayEngine::class)
            ->cambiarFechaDeSalida($estadia->fresh(), now()->addDays(3)->toDateString());

        $this->assertSame(3, (int) $extendida->nights);
    }

    /* ------------------------------------------------------------------ */
    /*  Check-out y factura */
    /* ------------------------------------------------------------------ */

    /**
     * El check-out factura el folio completo y cierra todo de una vez.
     *
     * Lo que importa: el total de la factura es el del folio. Si no coinciden,
     * el huésped paga una cifra distinta a la que se le enseñó.
     */
    public function test_el_checkout_factura_el_folio_y_cierra_la_estadia(): void
    {
        $iva = $this->impuesto('ZZIVAH19', 'IVA 19 ZZH', 'vat', 19);
        $habitacion = $this->habitacion($this->hotel, 1);

        $estadia = $this->alojar($habitacion, noches: 1);

        app(HotelFolio::class)->agregarConsumo($estadia, [
            'description' => 'Cerveza ZZ',
            'quantity' => 1,
            'unit_price' => 11900,
            'tax_id' => $iva->id,
        ]);

        $estadia = $estadia->fresh();
        $totalDelFolio = (float) $estadia->total;

        $factura = app(HotelBillingEngine::class)->issueForStay($estadia, [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => $this->cuentaDeCaja(),
        ]);

        $this->limpiar[] = fn () => $this->borraFactura((int) $factura->id);

        $this->assertEqualsWithDelta(
            $totalDelFolio,
            (float) $factura->total,
            1.0,
            'La factura tiene que cobrar lo mismo que decía el folio.',
        );

        $this->assertEqualsWithDelta((float) $factura->total, (float) $factura->paid_amount, 0.01);

        $cerrada = $estadia->fresh();
        $this->assertSame(Stay::STATUS_CLOSED, $cerrada->status);
        $this->assertSame((int) $factura->id, (int) $cerrada->sale_invoice_id);
        $this->assertNotNull($cerrada->check_out_at);
        $this->assertNotNull($cerrada->cash_register_session_id, 'La venta tiene que quedar en un turno.');

        // La habitación queda para aseo, no disponible: hay que tenderla.
        $this->assertSame(Room::STATUS_CLEANING, $habitacion->fresh()->status);
    }

    /**
     * Una rebaja del folio llega a la factura como descuento global.
     *
     * No como línea negativa: la DIAN las rechaza. Y el descuento global baja
     * la base gravable antes del IVA, que es lo que corresponde.
     */
    public function test_la_rebaja_del_folio_va_como_descuento_global(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 1);

        app(HotelFolio::class)->agregarAjuste($estadia, 'Cortesía ZZ', 20000);

        $estadia = $estadia->fresh();
        $totalDelFolio = (float) $estadia->total;

        $factura = app(HotelBillingEngine::class)->issueForStay($estadia, [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => $this->cuentaDeCaja(),
        ]);

        $this->limpiar[] = fn () => $this->borraFactura((int) $factura->id);

        $this->assertGreaterThan(0, (float) $factura->global_discount_amount, 'Debió aplicarse el descuento global.');
        $this->assertEqualsWithDelta($totalDelFolio, (float) $factura->total, 1.0);

        // Y ninguna línea quedó en negativo.
        foreach ($factura->lines as $linea) {
            $this->assertGreaterThanOrEqual(0, (float) $linea->total, 'La DIAN rechaza líneas negativas.');
        }
    }

    /** Un folio en cero no se factura: se cierra sin factura y con motivo. */
    public function test_no_se_factura_un_folio_ya_cerrado(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 1);

        $factura = app(HotelBillingEngine::class)->issueForStay($estadia->fresh(), [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => $this->cuentaDeCaja(),
        ]);

        $this->limpiar[] = fn () => $this->borraFactura((int) $factura->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cerrada/i');

        app(HotelBillingEngine::class)->issueForStay($estadia->fresh(), [
            'invoice_kind' => 'pos',
            'payment_method' => 'cash',
            'account_id' => $this->cuentaDeCaja(),
        ]);
    }

    /**
     * Cerrar sin facturar no toca la caja, pero sí descarga inventario.
     *
     * La cerveza salió de la nevera aunque no se cobrara. Es la misma regla
     * del «casa invita» del restaurante, y por eso usa el mismo servicio.
     */
    public function test_cerrar_sin_facturar_descarga_inventario_pero_no_cobra(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 1);
        $producto = $this->productoConInventario();

        app(HotelFolio::class)->agregarConsumo($estadia, [
            'product_id' => $producto->id,
            'quantity' => 2,
            'unit_price' => 5000,
        ]);

        $movimientosAntes = InventoryMovement::withoutGlobalScopes()
            ->where('product_id', $producto->id)
            ->count();

        $cerrada = app(HotelStayEngine::class)
            ->cerrarSinFacturar($estadia->fresh(), 'Habitación de cortesía ZZ');

        $this->assertSame(Stay::STATUS_CLOSED, $cerrada->status);
        $this->assertNull($cerrada->sale_invoice_id, 'No se factura: no entró plata.');
        $this->assertStringContainsString('cortesía', mb_strtolower((string) $cerrada->cancel_reason));

        $movimientosDespues = InventoryMovement::withoutGlobalScopes()
            ->where('product_id', $producto->id)
            ->count();

        $this->assertSame(
            $movimientosAntes + 1,
            $movimientosDespues,
            'El producto salió del almacén aunque no se haya cobrado.',
        );
    }

    /** Cerrar sin facturar exige motivo: es plata que el hotel no recibe. */
    public function test_cerrar_sin_facturar_exige_motivo(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 1);

        $this->expectException(\RuntimeException::class);

        app(HotelStayEngine::class)->cerrarSinFacturar($estadia->fresh(), '   ');
    }

    /**
     * Anular es para el check-in equivocado, no para dejar de cobrar.
     *
     * Con consumos ya cargados, anular borraría el rastro de lo que salió del
     * almacén; para eso está «cerrar sin facturar».
     */
    public function test_no_se_anula_una_estadia_con_consumos(): void
    {
        $estadia = $this->alojar($this->habitacion($this->hotel, 1), noches: 1);

        app(HotelFolio::class)->agregarConsumo($estadia, [
            'description' => 'Almuerzo ZZ',
            'quantity' => 1,
            'unit_price' => 25000,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/consumo/i');

        app(HotelStayEngine::class)->anular($estadia->fresh(), 'Me equivoqué ZZ');
    }

    /** Anular un check-in recién hecho devuelve la habitación a disponible. */
    public function test_anular_devuelve_la_habitacion_a_disponible(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);
        $estadia = $this->alojar($habitacion, noches: 1);

        $anulada = app(HotelStayEngine::class)->anular($estadia->fresh(), 'Habitación equivocada ZZ');

        $this->assertSame(Stay::STATUS_CANCELLED, $anulada->status);
        $this->assertEqualsWithDelta(0, (float) $anulada->total, 0.01);

        // A disponible y no a aseo: nadie durmió ahí.
        $this->assertSame(Room::STATUS_AVAILABLE, $habitacion->fresh()->status);
    }

    /** Una habitación en aseo vuelve al inventario al marcarla. */
    public function test_marcar_aseada_devuelve_la_habitacion(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);
        $habitacion->update(['status' => Room::STATUS_CLEANING]);

        $lista = app(HotelStayEngine::class)->marcarAseada($habitacion);

        $this->assertSame(Room::STATUS_AVAILABLE, $lista->status);
    }

    /** No se marca aseada una habitación con gente dentro. */
    public function test_no_se_marca_aseada_una_habitacion_ocupada(): void
    {
        $habitacion = $this->habitacion($this->hotel, 1);
        $this->alojar($habitacion, noches: 1);

        $this->expectException(\RuntimeException::class);

        app(HotelStayEngine::class)->marcarAseada($habitacion->fresh());
    }

    // --------------------------------------------------------- auxiliares

    private function alojar(Room $habitacion, int $noches): Stay
    {
        return $this->alojarEn($habitacion, now(), now()->copy()->addDays($noches));
    }

    private function alojarEn(Room $habitacion, Carbon $entrada, Carbon $salida): Stay
    {
        $estadia = app(HotelStayEngine::class)->checkIn([
            'hotel_id' => $this->hotel->id,
            'room_id' => $habitacion->id,
            'check_in_at' => $entrada->toDateTimeString(),
            'expected_check_out' => $salida->toDateString(),
        ]);

        $this->limpiar[] = fn () => $this->borraEstadia($estadia);

        return $estadia;
    }

    private function borraEstadia(Stay $estadia): void
    {
        DB::table('hotel_reservations')->where('stay_id', $estadia->id)->update(['stay_id' => null]);
        DB::table('hotel_stay_guests')->where('stay_id', $estadia->id)->delete();
        DB::table('hotel_stay_charges')->where('stay_id', $estadia->id)->delete();
        DB::table('hotel_stays')->where('id', $estadia->id)->delete();
    }

    /**
     * Deshace una factura y todo lo que arrastra.
     *
     * Los asientos se encuentran por `journal_entry_id` —de la factura y de
     * cada pago—, que es como el sistema los enlaza: `journal_entries` no
     * tiene columnas polimórficas.
     */
    private function borraFactura(int $facturaId): void
    {
        DB::table('hotel_stays')->where('sale_invoice_id', $facturaId)->update(['sale_invoice_id' => null]);

        $pagos = DB::table('payments')
            ->where('paymentable_type', 'App\\Models\\SaleInvoice')
            ->where('paymentable_id', $facturaId)
            ->get(['id', 'journal_entry_id']);

        $asientos = $pagos->pluck('journal_entry_id')->filter()->values();

        $deLaFactura = DB::table('sale_invoices')->where('id', $facturaId)->value('journal_entry_id');

        if ($deLaFactura) {
            $asientos->push($deLaFactura);
        }

        // El asiento de costo de ventas no cuelga de la factura: se encuentra
        // por su referencia, que lleva el número del documento.
        $numero = DB::table('sale_invoices')->where('id', $facturaId)->value('number');

        if ($numero) {
            DB::table('journal_entries')
                ->where('company_id', $this->empresa->id)
                ->where('type', 'cogs')
                ->where('reference', 'like', '%'.$numero.'%')
                ->pluck('id')
                ->each(fn ($id) => $asientos->push($id));
        }

        DB::table('payments')->whereIn('id', $pagos->pluck('id'))->delete();
        DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();
        DB::table('journal_entries')->whereIn('id', $asientos)->delete();
        DB::table('inventory_movements')
            ->where('reference_type', 'App\\Models\\SaleInvoice')
            ->where('reference_id', $facturaId)
            ->delete();
        DB::table('sale_invoice_lines')->where('sale_invoice_id', $facturaId)->delete();
        DB::table('sale_invoices')->where('id', $facturaId)->delete();
    }

    private function impuesto(string $codigo, string $nombre, string $tipo, float $tarifa): Tax
    {
        $existente = Tax::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->where('code', $codigo)
            ->first();

        if ($existente) {
            return $existente;
        }

        $impuesto = Tax::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => $codigo,
            'name' => $nombre,
            'type' => $tipo,
            'rate' => $tarifa,
            'is_active' => true,
        ]);

        $this->limpiar[] = fn () => DB::table('taxes')->where('id', $impuesto->id)->delete();

        return $impuesto;
    }

    private function productoConInventario(): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZMINIBAR'.random_int(100, 999),
            'name' => 'Minibar ZZ',
            'type' => 'good',
            'active' => true,
            'is_sellable' => true,
            'track_inventory' => true,
            'default_sale_price' => 5000,
            'sale_price_includes_tax' => false,
        ]);

        $this->limpiar[] = function () use ($producto) {
            DB::table('inventory_movements')->where('product_id', $producto->id)->delete();
            DB::table('product_locations')->where('product_id', $producto->id)->delete();
            DB::table('products')->where('id', $producto->id)->delete();
        };

        return $producto;
    }

    private function cuentaDeCaja(): int
    {
        return (int) PaymentAccountResolver::forMethod('cash');
    }

    /**
     * La sede necesita una resolución POS: sin consecutivo no hay factura, y
     * el check-out falla antes de llegar a lo que se está probando.
     */
    private function daleResolucionPosALaSede(): void
    {
        $resolucion = Resolution::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->empresa->id, 'prefix' => 'ZZH'],
            [
                'kind' => Resolution::KIND_POS,
                'document_type_id' => 1,
                // Del catálogo del modelo, no a mano: el tipo 1 es «Factura
                // Electrónica» para la DIAN, y un nombre que no corresponde
                // al código es lo que `DianDocumentTypeCodesTest` impide.
                'document_type_name' => Resolution::DOCUMENT_TYPES[1],
                'resolution_number' => 'ZZ-HOTEL',
                'range_from' => 1,
                'range_to' => 999999,
                'active' => true,
            ],
        );

        $vinculo = LocationResolution::withoutGlobalScopes()->firstOrCreate(
            ['location_id' => $this->sede->id, 'dian_resolution_id' => $resolucion->id],
            ['current_consecutive' => 0, 'active' => true],
        );

        $this->limpiar[] = fn () => LocationResolution::withoutGlobalScopes()
            ->whereKey($vinculo->id)->forceDelete();
        $this->limpiar[] = fn () => Resolution::withoutGlobalScopes()
            ->whereKey($resolucion->id)->forceDelete();
    }
}
