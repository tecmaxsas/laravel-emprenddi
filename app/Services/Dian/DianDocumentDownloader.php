<?php

namespace App\Services\Dian;

use App\Models\CreditDebitNote;
use App\Models\Dian\CompanyConfig;
use App\Models\SaleInvoice;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Trae de vuelta el PDF o el XML que la DIAN autorizó.
 *
 * La representación gráfica oficial de un documento electrónico no la genera
 * Emprenddi: la genera el proveedor tecnológico cuando la DIAN autoriza, con el
 * CUFE y el código QR ya incrustados. Un PDF hecho aquí se parecería mucho pero
 * no sería el documento válido, así que se descarga del proveedor.
 *
 * El nombre del archivo es la clave de todo: el endpoint es uno solo y lo que
 * decide qué devuelve es cómo se llama el archivo que se le pide.
 *
 *   FES-{prefijo}{numero}.pdf    Factura de venta
 *   NCS-{prefijo}{numero}.pdf    Nota crédito
 *   NDS-{prefijo}{numero}.pdf    Nota débito
 *
 * Cuando el proveedor ya nos dijo el nombre exacto —viene en la respuesta del
 * envío, guardada en `dian_response`— se usa ese y no el que armamos nosotros.
 * Es más confiable: si el proveedor cambia la convención, la respuesta sigue
 * trayendo el nombre bueno.
 */
class DianDocumentDownloader
{
    /**
     * Descarga el PDF del documento.
     *
     * @return array{contents: string, filename: string}
     *
     * @throws RuntimeException con un mensaje que se le puede mostrar al usuario
     */
    public function pdf(Model $documento): array
    {
        $this->validarQueSePuedaDescargar($documento);

        $nombre = $this->nombreDeArchivo($documento, 'pdf');
        $config = $this->configDe($documento);

        $resultado = (new DianApiClient($config))->downloadFile(
            $this->nitEmisor($documento),
            $nombre,
        );

        if (! $resultado['ok']) {
            throw new RuntimeException($this->explicar($resultado['error'], $nombre));
        }

        return ['contents' => $resultado['contents'], 'filename' => $nombre];
    }

    /**
     * Por qué no se puede descargar, o null si sí se puede.
     *
     * Igual que en el borrado de facturas: la pantalla necesita poder explicarlo
     * antes del clic, no después.
     */
    public function motivoParaNoDescargar(Model $documento): ?string
    {
        if ($documento->dian_status !== 'accepted') {
            return 'El PDF oficial lo genera la DIAN al autorizar el documento. '
                .'Este todavía no está aceptado, así que no existe aún.';
        }

        if (empty($documento->cufe)) {
            return 'El documento no tiene CUFE, así que la DIAN no lo autorizó.';
        }

        return null;
    }

    private function validarQueSePuedaDescargar(Model $documento): void
    {
        if ($razon = $this->motivoParaNoDescargar($documento)) {
            throw new RuntimeException($razon);
        }
    }

    /**
     * El nombre exacto del archivo en el proveedor.
     */
    private function nombreDeArchivo(Model $documento, string $extension): string
    {
        // Lo que el proveedor nos dijo al aceptar el documento manda sobre
        // cualquier convención que reconstruyamos nosotros.
        $respuesta = $documento->dian_response;

        if (is_array($respuesta)) {
            $clave = $extension === 'pdf' ? 'urlinvoicepdf' : 'urlinvoicexml';
            $delProveedor = $this->buscarClave($respuesta, $clave);

            if (is_string($delProveedor) && $delProveedor !== '') {
                return $delProveedor;
            }
        }

        return sprintf('%s-%s%s.%s',
            $this->prefijoDeTipo($documento),
            $documento->prefix,
            $documento->number,
            $extension,
        );
    }

    /**
     * El `dian_response` no siempre tiene la misma forma: a veces es la
     * respuesta cruda del proveedor y a veces viene envuelta. Se busca la clave
     * en profundidad en vez de asumir dónde quedó.
     */
    private function buscarClave(array $datos, string $clave): mixed
    {
        if (array_key_exists($clave, $datos)) {
            return $datos[$clave];
        }

        foreach ($datos as $valor) {
            if (is_array($valor)) {
                $encontrado = $this->buscarClave($valor, $clave);

                if ($encontrado !== null) {
                    return $encontrado;
                }
            }
        }

        return null;
    }

    private function prefijoDeTipo(Model $documento): string
    {
        if ($documento instanceof CreditDebitNote) {
            return $documento->isCredit() ? 'NCS' : 'NDS';
        }

        if ($documento instanceof SaleInvoice) {
            return $documento->isPosInvoice() ? 'POSS' : 'FES';
        }

        throw new RuntimeException('Tipo de documento sin convención de archivo definida.');
    }

    private function nitEmisor(Model $documento): string
    {
        // El NIT del endpoint es el del emisor y va sin dígito de verificación.
        $nit = $documento->company?->nit;

        if (! $nit) {
            throw new RuntimeException('La empresa no tiene NIT configurado.');
        }

        return preg_replace('/\D/', '', (string) $nit);
    }

    private function configDe(Model $documento): CompanyConfig
    {
        $config = CompanyConfig::withoutGlobalScopes()
            ->where('company_id', $documento->company_id)
            ->first();

        if (! $config || ! $config->api_token) {
            throw new RuntimeException(
                'La empresa no tiene configurada la facturación electrónica. '
                .'Revisa Configuración → DIAN.'
            );
        }

        return $config;
    }

    /**
     * Traduce el error del proveedor a algo accionable.
     *
     * «No se encontro el archivo» es el mensaje más común y el más confuso: casi
     * siempre significa que el documento se autorizó hace un momento y el PDF
     * todavía se está generando, no que se haya perdido.
     */
    private function explicar(?string $error, string $nombre): string
    {
        if ($error && str_contains(mb_strtolower($error), 'no se encontro')) {
            return "El proveedor todavía no tiene el archivo {$nombre}. "
                .'Si el documento se autorizó hace poco, espera un momento y vuelve a intentar.';
        }

        return $error ?? 'No fue posible descargar el archivo.';
    }
}
