<?php

namespace App\Support;

use App\Models\Company;

/**
 * Feature flags + parametros del modulo Citas / Agendamiento.
 * Almacenados en companies.settings.appointments.* (jsonb).
 *
 * El admin activa el modulo desde Configuraciones → Citas. Una vez activo,
 * aparecen la agenda (calendario) y el resource de citas en el menu, y se
 * pueden agendar servicios para clientes con un profesional asignado.
 */
class AppointmentsSettings
{
    /**
     * Definicion de cada setting para construir la UI y resolver defaults.
     */
    public const FEATURES = [
        'enabled' => [
            'label' => 'Activar módulo de citas',
            'description' => 'Habilita la agenda y el calendario. Una vez activo, podrás agendar servicios para clientes con un profesional asignado desde Citas.',
            'default' => false,
        ],
        'default_duration_minutes' => [
            'label' => 'Duración por defecto (minutos)',
            'description' => 'Duración sugerida al crear una cita nueva. Puedes ajustarla en cada cita.',
            'default' => 30,
        ],
        'require_client' => [
            'label' => 'Cliente obligatorio',
            'description' => 'Exige seleccionar un cliente al agendar. Si lo apagas, se pueden crear citas sin cliente y completarlas después.',
            'default' => false,
        ],
        'allow_overlap' => [
            'label' => 'Permitir solapamiento por profesional',
            'description' => 'Si lo apagas, el sistema advierte cuando un profesional ya tiene una cita en ese horario.',
            'default' => false,
        ],
    ];

    public static function get(string $key): mixed
    {
        if (! array_key_exists($key, self::FEATURES)) {
            return null;
        }
        $company = app(CurrentCompany::class)->get()
            ?? (auth()->user()?->company_id ? Company::find(auth()->user()->company_id) : null);
        $settings = $company?->settings ?? [];
        $stored = data_get($settings, "appointments.{$key}");
        return $stored ?? self::FEATURES[$key]['default'];
    }

    public static function isEnabled(string $key): bool
    {
        return (bool) self::get($key);
    }

    public static function moduleActive(): bool
    {
        return self::isEnabled('enabled');
    }

    public static function defaultDurationMinutes(): int
    {
        return (int) (self::get('default_duration_minutes') ?: 30);
    }

    public static function requiresClient(): bool
    {
        return self::isEnabled('require_client');
    }

    public static function allowsOverlap(): bool
    {
        return self::isEnabled('allow_overlap');
    }

    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $key) {
            $out[$key] = self::get($key);
        }
        return $out;
    }
}
