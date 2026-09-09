<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Cómo está conectada una empresa con Claude.
 *
 * Vive en `companies.settings['ai']`, junto al resto de la configuración. La
 * llave propia se guarda cifrada: es una credencial de facturación de un tercero
 * y quien tenga acceso a la base no tiene por qué poder usarla.
 */
class AiSettings
{
    /** La llave la pone Tecmax y la empresa consume de su saldo. */
    public const MODE_TECMAX = 'tecmax';

    /** La empresa pone su propia llave y le factura Anthropic. */
    public const MODE_OWN = 'propia';

    public const MODES = [
        self::MODE_TECMAX => 'Saldo con Tecmax',
        self::MODE_OWN => 'Mi propia cuenta de Anthropic',
    ];

    public function __construct(private readonly ?Company $company = null) {}

    public static function para(?Company $company = null): self
    {
        return new self($company ?? app(CurrentCompany::class)->get());
    }

    public function empresa(): ?Company
    {
        return $this->company;
    }

    public function habilitado(): bool
    {
        return (bool) $this->valor('enabled', false);
    }

    public function modo(): string
    {
        $modo = (string) $this->valor('mode', self::MODE_TECMAX);

        return array_key_exists($modo, self::MODES) ? $modo : self::MODE_TECMAX;
    }

    public function usaCuentaPropia(): bool
    {
        return $this->modo() === self::MODE_OWN;
    }

    public function modelo(): string
    {
        $modelo = (string) $this->valor('model', config('ai.default_model'));

        return array_key_exists($modelo, config('ai.models', [])) ? $modelo : config('ai.default_model');
    }

    /**
     * La llave que se usará para llamar a la API.
     *
     * En modo propio, la de la empresa; en modo Tecmax, la del servidor. Nunca
     * se mezclan: si la empresa eligió su cuenta y no cargó llave, la
     * integración queda sin funcionar y lo dice, en vez de cobrarle a Tecmax.
     */
    public function apiKey(): ?string
    {
        if ($this->usaCuentaPropia()) {
            return $this->apiKeyPropia();
        }

        return config('ai.api_key') ?: null;
    }

    public function apiKeyPropia(): ?string
    {
        $cifrada = $this->valor('api_key_encrypted');

        if (! $cifrada) {
            return null;
        }

        try {
            return Crypt::decryptString($cifrada);
        } catch (Throwable) {
            // Cambió APP_KEY o la fila se editó a mano. Se trata como si no
            // hubiera llave: es preferible pedirla otra vez que reventar.
            return null;
        }
    }

    /** Los últimos caracteres, para que el usuario reconozca cuál cargó. */
    public function pistaDeLlave(): ?string
    {
        $llave = $this->apiKeyPropia();

        return $llave ? '…'.mb_substr($llave, -6) : null;
    }

    /**
     * Guarda la configuración. La llave vacía deja la que había: el formulario
     * nunca muestra la llave completa, así que un campo en blanco significa
     * «no la cambié», no «bórrala».
     */
    public function guardar(array $datos): void
    {
        if (! $this->company) {
            return;
        }

        $ajustes = $this->company->settings ?? [];
        $ai = $ajustes['ai'] ?? [];

        $ai['enabled'] = (bool) ($datos['enabled'] ?? false);
        $ai['mode'] = array_key_exists($datos['mode'] ?? '', self::MODES)
            ? $datos['mode']
            : self::MODE_TECMAX;
        $ai['model'] = array_key_exists($datos['model'] ?? '', config('ai.models', []))
            ? $datos['model']
            : config('ai.default_model');

        if (! empty($datos['api_key'])) {
            $ai['api_key_encrypted'] = Crypt::encryptString(trim($datos['api_key']));
        }

        if ($datos['remove_api_key'] ?? false) {
            unset($ai['api_key_encrypted']);
        }

        $ajustes['ai'] = $ai;

        $this->company->update(['settings' => $ajustes]);
        app(CurrentCompany::class)->set($this->company->fresh());
    }

    /**
     * Por qué no se puede usar Claude ahora mismo, en palabras del usuario.
     * Null significa que sí se puede.
     */
    public function motivoParaNoUsar(): ?string
    {
        if (! $this->company) {
            return 'No hay una empresa activa.';
        }

        if (! $this->habilitado()) {
            return 'La integración con Claude está apagada. Actívala en la configuración.';
        }

        if ($this->usaCuentaPropia() && ! $this->apiKeyPropia()) {
            return 'Elegiste conectar tu propia cuenta de Anthropic pero todavía no has cargado la llave.';
        }

        if (! $this->usaCuentaPropia() && ! config('ai.api_key')) {
            return 'El servicio con saldo de Tecmax no está disponible en este servidor. '
                .'Comunícate con soporte o conecta tu propia cuenta de Anthropic.';
        }

        if (! $this->usaCuentaPropia() && app(AiCredits::class)->saldo($this->company) <= 0) {
            return 'Te quedaste sin saldo. Pide una recarga al equipo comercial con el botón de WhatsApp.';
        }

        return null;
    }

    private function valor(string $clave, mixed $porDefecto = null): mixed
    {
        return data_get($this->company?->settings ?? [], "ai.{$clave}", $porDefecto);
    }
}
