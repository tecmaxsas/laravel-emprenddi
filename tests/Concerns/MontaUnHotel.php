<?php

namespace Tests\Concerns;

use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\HotelZone;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use App\Models\Location;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * El escenario mínimo de un hotel, para no repetirlo en cada prueba.
 *
 * Trabaja sobre la empresa de la base de desarrollo —que ya tiene PUC,
 * impuestos, resolución y Consumidor Final— y le enciende el módulo `hotel`
 * mientras corre la prueba. Todo lo que crea lleva prefijo ZZ y se borra en la
 * cola de limpieza, en orden inverso.
 *
 * Levantar una empresa entera por prueba sería más limpio en teoría y en la
 * práctica significa sembrar el plan de cuentas completo cada vez: las
 * pruebas de facturación pasarían de segundos a minutos.
 */
trait MontaUnHotel
{
    protected Company $empresa;

    protected Location $sede;

    protected Hotel $hotel;

    protected RoomType $tipoDoble;

    /**
     * Corre la cola de limpieza COMPLETA, pase lo que pase.
     *
     * El `foreach` de toda la vida tiene un problema que no se ve hasta que
     * muerde: si un paso revienta, los que venían detrás no corren y lo que
     * creó la prueba se queda en la base de desarrollo.
     *
     * Pasó construyendo este módulo. Una corrida tenía mal el nombre de una
     * columna al deshacer una factura; tres pruebas fallaron **dentro del
     * tearDown** y dejaron atrás, entre otras cosas, un turno de caja abierto.
     * Las siguientes corridas de la suite se llevaron por delante tres pruebas
     * de caja —`CashSessionAttributionTest`, `PaymentDeleterTest`— que no
     * tienen nada que ver con hoteles. Horas de buscar el fallo donde no
     * estaba.
     *
     * Así que cada paso va aislado y el error NO se traga: se reporta al
     * final, cuando ya no puede impedir que lo demás se limpie. Visible y sin
     * dejar basura.
     *
     * @param  list<callable>  $limpiar
     */
    protected function limpiaLoCreado(array &$limpiar): void
    {
        $fallos = [];

        foreach (array_reverse($limpiar) as $paso) {
            try {
                $paso();
            } catch (\Throwable $e) {
                $fallos[] = $e->getMessage();
            }
        }

        $limpiar = [];

        if ($fallos !== []) {
            throw new \RuntimeException(
                'La limpieza de la prueba falló en '.count($fallos)." paso(s) —lo demás sí se limpió—:\n"
                .implode("\n", $fallos)
            );
        }
    }

    /**
     * Enciende el módulo `hotel` y lo apaga al terminar.
     *
     * @param  list<callable>  $limpiar
     */
    protected function enciendeElModuloHotel(Company $company, array &$limpiar): void
    {
        $original = $company->active_modules;

        if (in_array('hotel', $original ?? [], true)) {
            return;
        }

        $con = array_values(array_unique(array_merge($original ?? [], ['hotel'])));

        $this->aplicaModulos($company, $con);

        $limpiar[] = function () use ($company, $original) {
            $this->aplicaModulos($company, $original);
        };
    }

    /**
     * Hotel con una zona, un tipo «doble» con tarifa y las habitaciones que se
     * pidan.
     *
     * @param  list<callable>  $limpiar
     * @param  int  $habitaciones  cuántas crear (ZZ-101, ZZ-102, …)
     */
    protected function montaUnHotel(
        Company $company,
        Location $sede,
        array &$limpiar,
        int $habitaciones = 2,
        float $tarifa = 100000,
    ): Hotel {
        $sufijo = random_int(1000, 9999);

        $hotel = Hotel::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'code' => 'ZZH'.$sufijo,
            'name' => 'Hotel ZZ '.$sufijo,
            'default_location_id' => $sede->id,
            'default_invoice_kind' => 'pos',
            'check_in_time' => '15:00:00',
            'check_out_time' => '12:00:00',
            'active' => true,
        ]);

        $zona = HotelZone::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'hotel_id' => $hotel->id,
            'name' => 'Piso ZZ',
            'display_order' => 1,
            'active' => true,
        ]);

        $tipo = RoomType::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'hotel_id' => $hotel->id,
            'code' => 'ZZDOB',
            'name' => 'Doble ZZ',
            'max_adults' => 2,
            'max_children' => 1,
            'base_rate' => $tarifa,
            'extra_person_rate' => 0,
            'active' => true,
        ]);

        $creadas = [];

        for ($i = 1; $i <= $habitaciones; $i++) {
            $creadas[] = Room::withoutGlobalScopes()->create([
                'company_id' => $company->id,
                'hotel_id' => $hotel->id,
                'zone_id' => $zona->id,
                'room_type_id' => $tipo->id,
                'number' => 'ZZ-10'.$i,
                'status' => Room::STATUS_AVAILABLE,
                'max_occupancy' => 3,
                'active' => true,
            ]);
        }

        // El orden importa: las estadías y reservas apuntan a habitaciones, y
        // las habitaciones al tipo y la zona. Se borra de adentro hacia afuera.
        $limpiar[] = function () use ($hotel, $zona, $tipo, $creadas) {
            $ids = array_map(fn (Room $h) => $h->id, $creadas);

            $estadias = DB::table('hotel_stays')->where('hotel_id', $hotel->id)->pluck('id');
            DB::table('hotel_stay_guests')->whereIn('stay_id', $estadias)->delete();
            DB::table('hotel_stay_charges')->whereIn('stay_id', $estadias)->delete();
            DB::table('hotel_reservations')->where('hotel_id', $hotel->id)->update(['stay_id' => null]);
            DB::table('hotel_stays')->where('hotel_id', $hotel->id)->delete();
            DB::table('hotel_reservations')->where('hotel_id', $hotel->id)->delete();
            DB::table('hotel_rooms')->whereIn('id', $ids)->delete();
            DB::table('hotel_room_types')->where('id', $tipo->id)->delete();
            DB::table('hotel_zones')->where('id', $zona->id)->delete();
            DB::table('hotels')->where('id', $hotel->id)->delete();
        };

        $this->tipoDoble = $tipo;

        return $hotel;
    }

    /** @return Room */
    protected function habitacion(Hotel $hotel, int $indice = 1): Room
    {
        return Room::withoutGlobalScopes()
            ->where('hotel_id', $hotel->id)
            ->where('number', 'ZZ-10'.$indice)
            ->firstOrFail();
    }

    /**
     * Turno de caja abierto para el usuario actual. Sin esto el check-out
     * falla a propósito: una venta tiene que quedar en el cierre de alguien.
     *
     * @param  list<callable>  $limpiar
     */
    protected function abreUnTurnoDeCaja(Company $company, Location $sede, int $userId, array &$limpiar): void
    {
        $abierto = CashRegisterSession::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('cashier_user_id', $userId)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->exists();

        if ($abierto) {
            return;
        }

        $turno = CashRegisterSession::withoutGlobalScopes()->create([
            'company_id' => $company->id,
            'location_id' => $sede->id,
            'cashier_user_id' => $userId,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();
    }

    /** @param  list<string>|null  $modulos */
    private function aplicaModulos(Company $company, ?array $modulos): void
    {
        $company->update(['active_modules' => $modulos]);
        app(CurrentCompany::class)->set($company->fresh());

        // El usuario de `actingAs` sobrevive entre peticiones de la misma
        // prueba, así que su relación `company` se queda con los módulos
        // viejos y ModuleGate seguiría respondiendo lo de antes.
        auth()->user()?->unsetRelation('company');
    }
}
