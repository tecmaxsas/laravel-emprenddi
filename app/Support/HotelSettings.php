<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;

/**
 * Configuración del módulo Hoteles. Todo vive en company.settings.hotel.*
 *
 * Son las decisiones que cambian de hotel en hotel y que no se pueden
 * adivinar: si se cobra la salida tardía y con cuánta holgura, qué dice el pie
 * de la cuenta, si se exige documento al registrar.
 */
class HotelSettings
{
    /** Holgura por defecto tras la hora de salida, en minutos. */
    public const HOLGURA_POR_DEFECTO = 60;

    /**
     * ¿Se le cobra una noche más al que entrega tarde?
     *
     * Apagado, la salida tardía no suma nada y queda a discreción de
     * recepción cargar un servicio aparte.
     */
    public static function cobraSalidaTardia(?Company $company = null): bool
    {
        return self::config($company)['charge_late_checkout'];
    }

    /**
     * Minutos de gracia después de la hora pactada.
     *
     * Existe porque cobrar una noche completa a quien entregó cinco minutos
     * tarde es una pelea en el mostrador que el hotel pierde siempre.
     */
    public static function holguraDeSalida(?Company $company = null): int
    {
        return self::config($company)['late_checkout_grace_minutes'];
    }

    /** Pie de la cuenta del huésped. Vacío = no se imprime nada. */
    public static function pieDeFolio(?Company $company = null): string
    {
        return self::config($company)['folio_footer'];
    }

    /** Si el RNT va impreso en la cuenta. */
    public static function muestraRnt(?Company $company = null): bool
    {
        return self::config($company)['show_rnt'];
    }

    /**
     * Si el titular tiene que tener documento para poder alojarse.
     *
     * Encendido por defecto: la tarjeta de registro hotelero lo pide, y es lo
     * que el hotel presenta si alguien pregunta quién durmió ahí esa noche.
     * Los acompañantes nunca lo exigen —un menor de edad no lleva cédula—.
     */
    public static function exigeDocumento(?Company $company = null): bool
    {
        return self::config($company)['require_guest_document'];
    }

    /**
     * @return array{charge_late_checkout: bool, late_checkout_grace_minutes: int,
     *               folio_footer: string, show_rnt: bool, require_guest_document: bool}
     */
    public static function config(?Company $company = null): array
    {
        $company ??= self::empresaActual();
        $settings = $company?->settings ?? [];

        $holgura = (int) data_get($settings, 'hotel.late_checkout_grace_minutes', self::HOLGURA_POR_DEFECTO);

        return [
            'charge_late_checkout' => (bool) data_get($settings, 'hotel.charge_late_checkout', true),
            // Acotada: una holgura negativa cobraría antes de la hora y una de
            // doce horas no es holgura, es otra hora de salida.
            'late_checkout_grace_minutes' => max(0, min(360, $holgura)),
            'folio_footer' => trim((string) data_get($settings, 'hotel.folio_footer', '')),
            'show_rnt' => (bool) data_get($settings, 'hotel.show_rnt', true),
            'require_guest_document' => (bool) data_get($settings, 'hotel.require_guest_document', true),
        ];
    }

    protected static function empresaActual(): ?Company
    {
        $companyId = app(CurrentCompany::class)->scopeId() ?: Auth::user()?->company_id;

        return $companyId ? Company::find($companyId) : null;
    }
}
