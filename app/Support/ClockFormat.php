<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;

/**
 * Cómo se escriben las horas en pantalla.
 *
 * En Colombia la hora se lee casi siempre en formato de 12 horas: un cajero
 * que ve «6:15 pm» no se equivoca, con «18:15» a veces sí —y en un parqueadero
 * la hora de entrada es lo que decide cuánto se cobra—. Por eso el sistema
 * arrancó forzando 12 horas en todas partes.
 *
 * Pero no todos los negocios leen igual. Una empresa de transporte, una
 * clínica o cualquiera que trabaje por turnos suele preferir el formato de 24
 * horas, donde no hay forma de confundir la mañana con la tarde. Así que ahora
 * cada empresa elige el suyo, en Configuración → Formato de hora.
 *
 * Los formatos viven aquí y no sueltos por las vistas porque son una decisión
 * de producto, no un detalle de cada pantalla: si se escribieran en cada sitio,
 * cambiar la preferencia dejaría media aplicación en un formato y media en el
 * otro, que es peor que no poder elegir.
 *
 * Eran constantes. Ahora son métodos porque el valor depende de quién esté
 * mirando, y eso una constante no lo puede saber.
 */
class ClockFormat
{
    /** Lo que se le ofrece a la empresa en la configuración. */
    public const FORMATOS = [
        '12' => '12 horas — 6:15 pm (lo habitual en Colombia)',
        '24' => '24 horas — 18:15 (hora militar)',
    ];

    public const POR_DEFECTO = '12';

    /**
     * El formato que eligió la empresa, por empresa.
     *
     * Se guarda porque estos métodos se llaman dentro de bucles —una tabla de
     * cincuenta sesiones de parqueadero es cincuenta llamadas— y sin esto cada
     * fila sería una consulta.
     *
     * @var array<int, string>
     */
    private static array $cache = [];

    /** '12' o '24'. */
    public static function preferido(?Company $company = null): string
    {
        $company ??= self::empresaActual();

        if (! $company) {
            return self::POR_DEFECTO;
        }

        return self::$cache[$company->id] ??= self::normalizar(
            data_get($company->settings, 'clock.format')
        );
    }

    /** Hora sola, compacta. «6:15 pm» / «18:15» — para fichas y etiquetas. */
    public static function time(?Company $company = null): string
    {
        return self::es24($company) ? 'H:i' : 'g:i a';
    }

    /**
     * Hora sola, con el cero delante. «06:15 pm» / «18:15» — para tablas.
     *
     * En 24 horas ya viene con el cero, así que es el mismo formato.
     */
    public static function timePadded(?Company $company = null): string
    {
        return self::es24($company) ? 'H:i' : 'h:i a';
    }

    /** Fecha y hora. «15/09/2026 06:15 pm» / «15/09/2026 18:15» */
    public static function datetime(?Company $company = null): string
    {
        return 'd/m/Y '.self::timePadded($company);
    }

    /**
     * Fecha y hora con segundos. «15/09/2026 06:15:42 pm»
     *
     * Los segundos importan donde el minuto decide plata: la entrada y la
     * salida de un vehículo, que es lo que se factura.
     */
    public static function datetimeSeconds(?Company $company = null): string
    {
        return self::es24($company)
            ? 'd/m/Y H:i:s'
            : 'd/m/Y h:i:s a';
    }

    /** Para las pruebas y para cuando la empresa cambia su preferencia. */
    public static function olvidarCache(): void
    {
        self::$cache = [];
    }

    private static function es24(?Company $company = null): bool
    {
        return self::preferido($company) === '24';
    }

    /**
     * Un valor guardado que ya no existe no rompe la pantalla.
     *
     * Si mañana se quita una opción del catálogo, las empresas que la tenían
     * guardada vuelven al formato por defecto en vez de quedarse con una
     * cadena de formato inválida que `format()` imprimiría como basura.
     */
    private static function normalizar(mixed $valor): string
    {
        $valor = (string) $valor;

        return isset(self::FORMATOS[$valor]) ? $valor : self::POR_DEFECTO;
    }

    private static function empresaActual(): ?Company
    {
        $companyId = Auth::user()?->company_id;

        return $companyId ? Company::withoutGlobalScopes()->find($companyId) : null;
    }
}
