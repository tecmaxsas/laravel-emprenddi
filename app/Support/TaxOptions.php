<?php

namespace App\Support;

use App\Models\Tax;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listas de impuestos y retenciones para los selectores de las facturas.
 *
 * Antes cada selector traia sus opciones solo al escribir. Una empresa tiene
 * cinco o diez impuestos, no cinco mil: obligar a adivinar el nombre para que
 * aparezca el IVA del 19 % no protegia de nada y hacia lenta la digitacion.
 * Aqui se cargan todos de una vez y el buscador filtra sobre lo que ya esta.
 *
 * Las consultas se memorizan por peticion porque un repetidor con diez lineas
 * evalua sus opciones diez veces, y serian diez consultas identicas.
 */
class TaxOptions
{
    /** @var array<string, array<int, string>> */
    protected static array $memo = [];

    /** Los tres tipos que en Colombia son retencion y no impuesto. */
    public const RETENTION_TYPES = ['income_withholding', 'vat_withholding', 'ica_withholding'];

    /**
     * Impuestos aplicables a una factura: IVA, INC, y demas. Sin retenciones,
     * que tienen su propia seccion y su propia base.
     *
     * @return array<int, string>
     */
    public static function taxes(string $applies): array
    {
        return static::memo("taxes:{$applies}", fn () => static::query($applies)
            ->whereNotIn('type', static::RETENTION_TYPES)
            ->get()
            ->mapWithKeys(fn (Tax $t) => [$t->id => static::shortLabel($t)])
            ->all());
    }

    /**
     * Retenciones aplicables. El nombre va completo porque «RTF» y «RTF-SERV»
     * no se distinguen solo por el codigo.
     *
     * @return array<int, string>
     */
    public static function retentions(string $applies): array
    {
        return static::memo("retentions:{$applies}", fn () => static::query($applies)
            ->whereIn('type', static::RETENTION_TYPES)
            ->get()
            ->mapWithKeys(fn (Tax $t) => [$t->id => static::longLabel($t)])
            ->all());
    }

    /** Etiqueta corta, para la rejilla de lineas donde el ancho es escaso. */
    public static function shortLabel(Tax $tax): string
    {
        return $tax->code.' '.static::rate($tax).'%';
    }

    /** Etiqueta con nombre, para la seccion de retenciones. */
    public static function longLabel(Tax $tax): string
    {
        return $tax->code.' — '.$tax->name.' ('.static::rate($tax).'%)';
    }

    /**
     * La tarifa se guarda con cuatro decimales, asi que el IVA se veia como
     * «19.0000%» y ocupaba media columna. Se muestran solo los decimales que
     * de verdad tiene: 19 %, 3,5 %, 0,4 %.
     */
    public static function rate(Tax $tax): string
    {
        $rate = rtrim(rtrim(number_format((float) $tax->rate, 4, ',', ''), '0'), ',');

        return $rate === '' ? '0' : $rate;
    }

    /** @return Builder<Tax> */
    protected static function query(string $applies)
    {
        return Tax::query()
            ->where('company_id', auth()->user()?->company_id)
            ->where('is_active', true)
            ->whereIn('applies_to', [$applies, 'both'])
            ->orderBy('code');
    }

    /**
     * @param  callable(): array<int, string>  $resolver
     * @return array<int, string>
     */
    protected static function memo(string $clave, callable $resolver): array
    {
        $clave = (auth()->user()?->company_id ?? 0).':'.$clave;

        return static::$memo[$clave] ??= $resolver();
    }

    /** Para las pruebas, que crean impuestos despues de haber leido la lista. */
    public static function forget(): void
    {
        static::$memo = [];
    }
}
