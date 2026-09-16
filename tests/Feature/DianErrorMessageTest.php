<?php

namespace Tests\Feature;

use App\Services\Dian\DianErrorReader;
use Tests\TestCase;

/**
 * Leer el error del proveedor sin romperse ni perderlo.
 *
 * El bloque `errors` de apidian no tiene una forma fija. A veces es
 * `{campo: ["mensaje"]}` como cualquier validación de Laravel, y a veces el
 * proveedor mete otro nivel. Los dos servicios de envío recorrían un solo nivel
 * y hacían `(string) $msg`, así que ese segundo nivel reventaba con «Array to
 * string conversion».
 *
 * Lo grave no era el error en sí: era que se perdía el mensaje. El usuario veía
 * «Array to string conversion» en vez de «la resolución no está configurada»,
 * que es justo lo que necesitaba para arreglar el envío.
 *
 * Todo esto es lectura de arreglos: no toca red ni base de datos.
 */
class DianErrorMessageTest extends TestCase
{
    /** La forma común: campo → lista de mensajes. */
    public function test_la_forma_plana_de_siempre(): void
    {
        $resumen = DianErrorReader::resumen([
            'number' => ['number tiene que estar entre 990000001 y 990001000'],
            'resolution' => ['La resolución no está configurada.'],
        ]);

        $this->assertStringContainsString('La resolución no está configurada.', $resumen);
        $this->assertStringContainsString('990000001', $resumen);
    }

    /** La que reventaba: un nivel más de anidamiento. */
    public function test_la_forma_anidada_no_revienta(): void
    {
        $resumen = DianErrorReader::resumen([
            'invoice_lines' => [
                '0' => ['tax_totals' => ['El impuesto es obligatorio.']],
            ],
        ]);

        $this->assertSame('El impuesto es obligatorio.', $resumen);
    }

    /** Un string suelto en vez de una lista tampoco. */
    public function test_un_mensaje_suelto_sin_lista(): void
    {
        $this->assertSame('Todo mal',
            DianErrorReader::resumen(['error' => 'Todo mal']));
    }

    /** Ni una mezcla de todo a la vez. */
    public function test_una_mezcla_de_formas(): void
    {
        $resumen = DianErrorReader::resumen([
            'a' => 'suelto',
            'b' => ['en lista'],
            'c' => ['sub' => ['anidado']],
            'd' => [['doble', 'lista']],
        ]);

        foreach (['suelto', 'en lista', 'anidado', 'doble', 'lista'] as $esperado) {
            $this->assertStringContainsString($esperado, $resumen);
        }
    }

    /** Un error sin texto no puede quedar en blanco: eso no le dice nada a nadie. */
    public function test_sin_texto_igual_dice_algo(): void
    {
        $this->assertNotSame('', DianErrorReader::resumen([]));
        $this->assertStringContainsString('sin detalle', DianErrorReader::resumen([]));
    }

    /**
     * La basura de la serialización XML no es un mensaje.
     *
     * apidian convierte SOAP a JSON, así que un elemento vacío llega como
     * `{"_attributes": {"nil": "true"}}`. Recorrer eso a lo bruto devuelve
     * «true», y el usuario veía «Error: true».
     */
    public function test_los_marcadores_de_vacio_no_son_mensajes(): void
    {
        $resumen = DianErrorReader::resumen([
            'ErrorMessageList' => ['_attributes' => ['nil' => 'true']],
        ]);

        $this->assertStringNotContainsString('true', $resumen);
        $this->assertStringContainsString('sin detalle', $resumen);
    }

    /** No repite el mismo mensaje veinte veces. */
    public function test_no_repite_el_mismo_mensaje(): void
    {
        $resumen = DianErrorReader::resumen([
            'a' => ['El impuesto es obligatorio.'],
            'b' => ['El impuesto es obligatorio.'],
            'c' => ['El impuesto es obligatorio.'],
        ]);

        $this->assertSame('El impuesto es obligatorio.', $resumen);
    }

    /**
     * Ningún servicio de envío puede volver a aplanar errores por su cuenta.
     *
     * El fallo estaba copiado en los dos senders. Mientras la lógica viva en un
     * solo sitio, arreglarla una vez alcanza; si alguien la vuelve a copiar, el
     * error vuelve en el servicio que se quedó atrás.
     */
    public function test_los_senders_no_aplanan_por_su_cuenta(): void
    {
        $culpables = [];

        $senders = [
            app_path('Services/Dian/DianInvoiceSender.php'),
            app_path('Services/Dian/CreditDebitNoteSender.php'),
        ];

        foreach ($senders as $ruta) {
            $contenido = file_get_contents($ruta);

            // El molde del fallo: castear a string dentro de un recorrido.
            if (preg_match('/foreach[^}]{0,200}\(string\)\s*\$msg/s', $contenido)) {
                $culpables[] = basename($ruta);
            }
        }

        $this->assertSame([], $culpables,
            'Estos servicios volvieron a aplanar errores a mano en vez de usar '
            .'DianErrorReader::resumen(): '.implode(', ', $culpables));
    }
}
