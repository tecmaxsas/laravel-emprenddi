<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Parking\ParkingIncident;
use App\Models\Parking\ParkingLot;
use App\Models\Parking\ParkingMembership;
use App\Models\Parking\ParkingRate;
use App\Models\Parking\ParkingSession;
use App\Models\Parking\ParkingSpace;
use App\Models\Parking\VehicleType;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Onboarding\CompanyOnboarding;
use App\Services\Parking\ParkingDefaultsProvisioner;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Datos demo del modulo parqueadero para mostrar a clientes potenciales.
 *
 * IDEMPOTENTE: puede correrse multiples veces. Detecta lo que ya existe
 * por codigo y solo crea lo faltante. Las sesiones historicas se generan
 * relativas a now(), asi que cada corrida refresca la fecha.
 *
 *   docker exec emprenddi_app php artisan db:seed --class=ParkingDemoSeeder
 *
 * Crea:
 *   - Empresa "Parqueaderos del Centro S.A.S." (o reusa si existe)
 *   - Admin demo@parqueaderoscentro.co (password: Demo2026!)
 *   - 4 tipos de vehiculo canonicos
 *   - 3 parqueaderos (urbano, centro comercial, eventos)
 *   - 6 tarifas (per_hour, per_minute, flat, tiered, per_day, ticket perdido)
 *   - 38 espacios distribuidos en zonas, algunos accesibles
 *   - 5 sesiones activas (vehiculos dentro ahora)
 *   - 60 sesiones historicas cerradas en los ultimos 14 dias
 *   - 3 mensualidades individuales + 2 convenios corporativos
 *   - 4 incidentes (2 abiertos, 2 resueltos)
 */
class ParkingDemoSeeder extends Seeder
{
    protected Company $company;
    protected User $admin;
    protected array $vehicleTypes = [];     // code => VehicleType
    protected array $lots = [];             // code => ParkingLot
    protected array $rates = [];            // name => ParkingRate

    public function run(): void
    {
        $this->command->info('🅿️  Seeding Parking Demo Data...');

        $this->ensureCompany();
        $this->ensureAdmin();
        $this->bootstrapCompany();
        $this->provisionVehicleTypes();
        $this->createLots();
        $this->createRates();
        $this->createSpaces();
        $this->createMemberships();
        $this->createHistoricalSessions();
        $this->createActiveSessions();
        $this->createIncidents();

        $this->command->newLine();
        $this->command->info('✅ Demo de parqueadero listo.');
        $this->command->line("   Empresa:  {$this->company->name}");
        $this->command->line("   Admin:    {$this->admin->email}");
        $this->command->line("   Password: Demo2026!");
        $this->command->line("   URL:      /app");
    }

    /* ------------------------------------------------------------------ */
    /*  Empresa + admin                                                    */
    /* ------------------------------------------------------------------ */

    protected function ensureCompany(): void
    {
        $this->company = Company::firstOrCreate(
            ['nit' => '901555888'],
            [
                'name' => 'Parqueaderos del Centro S.A.S.',
                'legal_name' => 'Parqueaderos del Centro Sociedad por Acciones Simplificada',
                'document_type' => 'nit',
                'organization_type' => 'juridica',
                'dv' => '4',
                'regime_type' => 'comun',
                'accounting_method' => 'niif_pymes',
                'inventory_method' => 'weighted_average',
                'address' => 'Carrera 7 #45-20',
                'city' => 'Bogotá',
                'department' => 'Cundinamarca',
                'phone' => '6017654321',
                'phone_country_code' => '+57',
                'email' => 'contacto@parqueaderoscentro.co',
                'currency' => 'COP',
                'timezone' => 'America/Bogota',
                'active_modules' => ['parking'],
                'active' => true,
            ],
        );

        // Asegura que parking este activo aun si la empresa ya existia
        $modules = $this->company->active_modules ?? [];
        if (! in_array('parking', $modules, true)) {
            $modules[] = 'parking';
            $this->company->update(['active_modules' => array_values(array_unique($modules))]);
        }
    }

    protected function ensureAdmin(): void
    {
        $this->admin = User::firstOrCreate(
            ['email' => 'demo@parqueaderoscentro.co'],
            [
                'company_id' => $this->company->id,
                'name' => 'Admin',
                'last_name' => 'Parqueaderos',
                'password' => Hash::make('Demo2026!'),
                'is_super_admin' => false,
                'active' => true,
                'email_verified_at' => now(),
            ],
        );
        if (Schema::hasTable('roles')) {
            try { $this->admin->assignRole('admin'); } catch (\Throwable) {}
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Onboarding: PUC, impuestos, sede principal, Consumidor Final...   */
    /* ------------------------------------------------------------------ */

    protected function bootstrapCompany(): void
    {
        // Idempotente: si ya estan, no duplica
        $summary = app(CompanyOnboarding::class)->bootstrap($this->company);
        $this->command->line("   · Onboarding: PUC={$summary['puc']}, impuestos={$summary['taxes']}, sede={$summary['location']}, Consumidor Final={$summary['consumer_final']}");
    }

    /* ------------------------------------------------------------------ */
    /*  Catalogo: tipos de vehiculo                                        */
    /* ------------------------------------------------------------------ */

    protected function provisionVehicleTypes(): void
    {
        app(ParkingDefaultsProvisioner::class)->provision($this->company);

        foreach (VehicleType::query()->where('company_id', $this->company->id)->get() as $vt) {
            $this->vehicleTypes[$vt->code] = $vt;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Parqueaderos                                                       */
    /* ------------------------------------------------------------------ */

    protected function createLots(): void
    {
        $lots = [
            [
                'code' => 'P01-URBANO',
                'name' => 'Parqueadero Centro Urbano',
                'address' => 'Carrera 7 #45-20',
                'city' => 'Bogotá',
                'phone' => '6011112233',
                'total_capacity' => 24,
            ],
            [
                'code' => 'P02-MALL',
                'name' => 'Parqueadero Centro Comercial',
                'address' => 'Av. Calle 80 #45-20',
                'city' => 'Bogotá',
                'phone' => '6014445566',
                'total_capacity' => 50,
            ],
            [
                'code' => 'P03-EVENTOS',
                'name' => 'Parqueadero Eventos',
                'address' => 'Calle 26 #100-00',
                'city' => 'Bogotá',
                'phone' => '6017778899',
                'total_capacity' => 80,
            ],
        ];

        $mainLocationId = \App\Models\Location::query()
            ->where('company_id', $this->company->id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->value('id');

        foreach ($lots as $data) {
            $lot = ParkingLot::firstOrCreate(
                ['company_id' => $this->company->id, 'code' => $data['code']],
                array_merge($data, [
                    'company_id' => $this->company->id,
                    'default_location_id' => $mainLocationId,
                    'default_invoice_kind' => 'pos',
                    'active' => true,
                    'settings' => [],
                ]),
            );
            // Si el lot existia sin sede asignada, la setea ahora
            if ($mainLocationId && ! $lot->default_location_id) {
                $lot->update(['default_location_id' => $mainLocationId]);
            }
            $this->lots[$data['code']] = $lot;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Tarifas: muestrario de los 5 esquemas                              */
    /* ------------------------------------------------------------------ */

    protected function createRates(): void
    {
        $rates = [
            [
                'lot' => 'P01-URBANO', 'vehicle' => VehicleType::CODE_CAR,
                'name' => 'Carro · Por hora',
                'kind' => ParkingRate::KIND_REGULAR,
                'config' => [
                    'type' => 'per_hour', 'amount' => 3500, 'rounding' => 'ceil',
                    'free_minutes' => 10, 'daily_cap' => 28000,
                    'lost_ticket_amount' => 45000,
                ],
                'priority' => 100,
            ],
            [
                'lot' => 'P01-URBANO', 'vehicle' => VehicleType::CODE_MOTORCYCLE,
                'name' => 'Moto · Por hora',
                'kind' => ParkingRate::KIND_REGULAR,
                'config' => [
                    'type' => 'per_hour', 'amount' => 1800, 'rounding' => 'ceil',
                    'free_minutes' => 10, 'daily_cap' => 15000,
                    'lost_ticket_amount' => 25000,
                ],
                'priority' => 100,
            ],
            [
                'lot' => 'P02-MALL', 'vehicle' => VehicleType::CODE_CAR,
                'name' => 'Carro · Escalonada (1ra hora flat)',
                'kind' => ParkingRate::KIND_REGULAR,
                'config' => [
                    'type' => 'tiered',
                    'free_minutes' => 15,
                    'daily_cap' => 35000,
                    'tiers' => [
                        ['from_min' => 0, 'to_min' => 60, 'type' => 'flat', 'amount' => 4500, 'rounding' => 'none'],
                        ['from_min' => 60, 'to_min' => 240, 'type' => 'per_hour', 'amount' => 3500, 'rounding' => 'ceil'],
                        ['from_min' => 240, 'to_min' => null, 'type' => 'per_hour', 'amount' => 2500, 'rounding' => 'ceil'],
                    ],
                    'lost_ticket_amount' => 50000,
                ],
                'priority' => 100,
            ],
            [
                'lot' => 'P02-MALL', 'vehicle' => VehicleType::CODE_MOTORCYCLE,
                'name' => 'Moto · Por minuto',
                'kind' => ParkingRate::KIND_REGULAR,
                'config' => [
                    'type' => 'per_minute', 'amount' => 35, 'rounding' => 'ceil',
                    'free_minutes' => 5, 'daily_cap' => 12000,
                    'lost_ticket_amount' => 20000,
                ],
                'priority' => 100,
            ],
            [
                'lot' => 'P03-EVENTOS', 'vehicle' => null,
                'name' => 'Eventos · Tarifa fija por evento',
                'kind' => ParkingRate::KIND_EVENT,
                'config' => [
                    'type' => 'flat', 'amount' => 12000,
                    'lost_ticket_amount' => 25000,
                ],
                'priority' => 200,
            ],
            [
                'lot' => 'P03-EVENTOS', 'vehicle' => VehicleType::CODE_CAR,
                'name' => 'Carro · Por día (24h)',
                'kind' => ParkingRate::KIND_REGULAR,
                'config' => [
                    'type' => 'per_day', 'amount' => 18000,
                    'free_minutes' => 0,
                    'lost_ticket_amount' => 30000,
                ],
                'priority' => 100,
            ],
        ];

        foreach ($rates as $data) {
            $vehicleTypeId = $data['vehicle']
                ? $this->vehicleTypes[$data['vehicle']]?->id
                : null;
            $rate = ParkingRate::firstOrCreate(
                [
                    'company_id' => $this->company->id,
                    'parking_lot_id' => $this->lots[$data['lot']]->id,
                    'name' => $data['name'],
                ],
                [
                    'company_id' => $this->company->id,
                    'parking_lot_id' => $this->lots[$data['lot']]->id,
                    'vehicle_type_id' => $vehicleTypeId,
                    'kind' => $data['kind'],
                    'config' => $data['config'],
                    'valid_from' => now()->subYear()->toDateString(),
                    'valid_to' => null,
                    'priority' => $data['priority'],
                    'active' => true,
                ],
            );
            $this->rates[$data['name']] = $rate;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Espacios fisicos                                                   */
    /* ------------------------------------------------------------------ */

    protected function createSpaces(): void
    {
        $layouts = [
            // P01-URBANO: 1 zona "Calle"
            'P01-URBANO' => [
                ['zone' => 'Calle', 'count' => 12, 'prefix' => 'C', 'vehicle' => null, 'accessibility' => [3]],
                ['zone' => 'Motos', 'count' => 8, 'prefix' => 'M', 'vehicle' => VehicleType::CODE_MOTORCYCLE],
            ],
            // P02-MALL: 2 pisos
            'P02-MALL' => [
                ['zone' => 'Piso 1', 'count' => 10, 'prefix' => 'A', 'vehicle' => null, 'accessibility' => [1, 2]],
                ['zone' => 'Piso 2', 'count' => 10, 'prefix' => 'B', 'vehicle' => null],
                ['zone' => 'Sótano', 'count' => 10, 'prefix' => 'S', 'vehicle' => null],
            ],
            // P03-EVENTOS: zonas Norte/Sur
            'P03-EVENTOS' => [
                ['zone' => 'Norte', 'count' => 15, 'prefix' => 'N', 'vehicle' => null, 'accessibility' => [1]],
                ['zone' => 'Sur', 'count' => 15, 'prefix' => 'S', 'vehicle' => null],
            ],
        ];

        foreach ($layouts as $lotCode => $zones) {
            $lot = $this->lots[$lotCode];
            foreach ($zones as $zone) {
                for ($i = 1; $i <= $zone['count']; $i++) {
                    $code = $zone['prefix'].'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                    ParkingSpace::firstOrCreate(
                        ['parking_lot_id' => $lot->id, 'code' => $code],
                        [
                            'company_id' => $this->company->id,
                            'parking_lot_id' => $lot->id,
                            'vehicle_type_id' => $zone['vehicle']
                                ? $this->vehicleTypes[$zone['vehicle']]?->id
                                : null,
                            'zone' => $zone['zone'],
                            'status' => ParkingSpace::STATUS_FREE,
                            'is_accessibility' => in_array($i, $zone['accessibility'] ?? [], true),
                            'notes' => null,
                        ],
                    );
                }
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Mensualidades + convenios                                          */
    /* ------------------------------------------------------------------ */

    protected function createMemberships(): void
    {
        $customers = $this->ensureCustomers();

        $memberships = [
            [
                'kind' => 'individual', 'lot' => 'P01-URBANO',
                'customer' => 'juan-perez', 'vehicle' => VehicleType::CODE_CAR,
                'name' => 'Mensualidad MQR-123 · Juan Pérez',
                'plates' => ['MQR123'], 'amount' => 220000,
                'days_remaining' => 12,
            ],
            [
                'kind' => 'individual', 'lot' => 'P02-MALL',
                'customer' => 'maria-rodriguez', 'vehicle' => VehicleType::CODE_CAR,
                'name' => 'Mensualidad XYZ-987 · María Rodríguez',
                'plates' => ['XYZ987'], 'amount' => 285000,
                'days_remaining' => 5,
            ],
            [
                'kind' => 'individual', 'lot' => 'P01-URBANO',
                'customer' => 'carlos-lopez', 'vehicle' => VehicleType::CODE_MOTORCYCLE,
                'name' => 'Mensualidad TYM-45D · Carlos López',
                'plates' => ['TYM45D'], 'amount' => 95000,
                'days_remaining' => 28,
            ],
            [
                'kind' => 'corporate', 'lot' => 'P02-MALL',
                'customer' => 'acme-sas', 'vehicle' => null,
                'name' => 'Convenio Corporativo Acme SAS (10 placas)',
                'plates' => ['ACM001', 'ACM002', 'ACM003', 'ACM004', 'ACM005',
                             'ACM006', 'ACM007', 'ACM008', 'ACM009', 'ACM010'],
                'amount' => 2400000, 'days_remaining' => 45,
            ],
            [
                'kind' => 'corporate', 'lot' => 'P03-EVENTOS',
                'customer' => 'cinemas-plaza', 'vehicle' => null,
                'name' => 'Convenio Cinemas Plaza (15 placas)',
                'plates' => array_map(fn ($n) => 'CIN'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), range(1, 15)),
                'amount' => 3500000, 'days_remaining' => 60,
            ],
        ];

        foreach ($memberships as $data) {
            ParkingMembership::firstOrCreate(
                [
                    'company_id' => $this->company->id,
                    'parking_lot_id' => $this->lots[$data['lot']]->id,
                    'name' => $data['name'],
                ],
                [
                    'company_id' => $this->company->id,
                    'parking_lot_id' => $this->lots[$data['lot']]->id,
                    'third_party_id' => $customers[$data['customer']]->id,
                    'vehicle_type_id' => $data['vehicle']
                        ? $this->vehicleTypes[$data['vehicle']]?->id
                        : null,
                    'kind' => $data['kind'],
                    'plates' => $data['plates'],
                    'start_date' => now()->subDays(30 - $data['days_remaining'])->toDateString(),
                    'end_date' => now()->addDays($data['days_remaining'])->toDateString(),
                    'amount' => $data['amount'],
                    'status' => ParkingMembership::STATUS_ACTIVE,
                    'auto_renew' => $data['kind'] === 'corporate',
                    'notes' => null,
                    'created_by_user_id' => $this->admin->id,
                ],
            );
        }
    }

    /**
     * Crea/retorna clientes demo para asociar a mensualidades.
     * @return array<string, ThirdParty>
     */
    protected function ensureCustomers(): array
    {
        $defs = [
            'juan-perez' => [
                'name' => 'Juan Pérez García', 'document_number' => '79456123',
                'document_type' => 'cc', 'person_type' => 'natural',
            ],
            'maria-rodriguez' => [
                'name' => 'María Camila Rodríguez', 'document_number' => '52234567',
                'document_type' => 'cc', 'person_type' => 'natural',
            ],
            'carlos-lopez' => [
                'name' => 'Carlos Andrés López', 'document_number' => '80111222',
                'document_type' => 'cc', 'person_type' => 'natural',
            ],
            'acme-sas' => [
                'name' => 'Acme S.A.S.', 'document_number' => '900111222',
                'document_type' => 'nit', 'person_type' => 'juridica',
            ],
            'cinemas-plaza' => [
                'name' => 'Cinemas Plaza S.A.S.', 'document_number' => '900333444',
                'document_type' => 'nit', 'person_type' => 'juridica',
            ],
        ];

        $out = [];
        foreach ($defs as $key => $data) {
            $out[$key] = ThirdParty::firstOrCreate(
                ['company_id' => $this->company->id, 'document_number' => $data['document_number']],
                array_merge($data, [
                    'company_id' => $this->company->id,
                    'is_customer' => true,
                    'address' => 'Bogotá D.C.',
                    'active' => true,
                ]),
            );
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Sesiones cerradas (historico para reportes)                        */
    /* ------------------------------------------------------------------ */

    protected function createHistoricalSessions(): void
    {
        // Si ya hay >= 50 sesiones cerradas, asume que el seed corrio antes
        $existing = ParkingSession::query()
            ->where('company_id', $this->company->id)
            ->where('status', '!=', ParkingSession::STATUS_ACTIVE)
            ->count();
        if ($existing >= 50) {
            $this->command->line("   · Sesiones históricas: {$existing} ya existen, no re-genero.");
            return;
        }

        $plates = $this->randomPlatePool(60);
        $created = 0;

        for ($i = 0; $i < 60; $i++) {
            $daysAgo = random_int(0, 13);
            $entryHour = random_int(7, 19);
            $entryMin = random_int(0, 59);
            $entry = now()->subDays($daysAgo)->setTime($entryHour, $entryMin);

            // Duracion variable: 80% < 4h, 15% 4-12h, 5% > 12h
            $r = random_int(1, 100);
            if ($r <= 80) {
                $minutes = random_int(15, 4 * 60);
            } elseif ($r <= 95) {
                $minutes = random_int(4 * 60, 12 * 60);
            } else {
                $minutes = random_int(12 * 60, 24 * 60);
            }
            $exit = $entry->copy()->addMinutes($minutes);

            $lotCode = ['P01-URBANO', 'P02-MALL', 'P03-EVENTOS'][random_int(0, 2)];
            $lot = $this->lots[$lotCode];
            $vehicleCode = random_int(1, 100) <= 75
                ? VehicleType::CODE_CAR
                : VehicleType::CODE_MOTORCYCLE;
            $vt = $this->vehicleTypes[$vehicleCode];

            // 4% ticket perdido, 2% anulada, resto cerrada normal
            $statusRoll = random_int(1, 100);
            if ($statusRoll <= 4) {
                $status = ParkingSession::STATUS_LOST_TICKET;
                $amount = $vehicleCode === VehicleType::CODE_CAR ? 45000 : 25000;
            } elseif ($statusRoll <= 6) {
                $status = ParkingSession::STATUS_CANCELLED;
                $amount = 0;
            } else {
                $status = ParkingSession::STATUS_CLOSED;
                $amount = $this->estimateAmount($lotCode, $vehicleCode, $minutes);
            }

            ParkingSession::create([
                'company_id' => $this->company->id,
                'parking_lot_id' => $lot->id,
                'vehicle_type_id' => $vt->id,
                'plate' => $plates[$i],
                'entry_at' => $entry,
                'exit_at' => $status === ParkingSession::STATUS_CANCELLED ? null : $exit,
                'status' => $status,
                'total_minutes' => $status === ParkingSession::STATUS_CANCELLED ? null : $minutes,
                'free_minutes' => $status === ParkingSession::STATUS_CLOSED ? min(10, $minutes) : 0,
                'charge_minutes' => $status === ParkingSession::STATUS_CLOSED ? max(0, $minutes - 10) : 0,
                'amount' => $amount,
                'paid_amount' => $status === ParkingSession::STATUS_CLOSED ? $amount : ($status === ParkingSession::STATUS_LOST_TICKET ? $amount : 0),
                'payment_method' => $amount > 0 ? ['cash', 'card'][random_int(0, 1)] : null,
                'cap_applied' => false,
                'breakdown' => $amount > 0 ? [['label' => 'Cobro estimado demo', 'amount' => (float) $amount]] : null,
                'created_by_user_id' => $this->admin->id,
                'closed_by_user_id' => $this->admin->id,
                'closed_at' => $status === ParkingSession::STATUS_CANCELLED ? $entry->copy()->addMinutes(5) : $exit,
                'cancel_reason' => $status === ParkingSession::STATUS_CANCELLED ? 'Error de registro (demo)' : null,
            ]);
            $created++;
        }
        $this->command->line("   · Sesiones históricas creadas: {$created}");
    }

    protected function estimateAmount(string $lotCode, string $vehicleCode, int $minutes): float
    {
        // Estimaciones aproximadas que coinciden con las tarifas creadas
        $cap = $lotCode === 'P02-MALL' ? 35000 : ($lotCode === 'P01-URBANO' ? 28000 : 18000);
        if ($vehicleCode === VehicleType::CODE_MOTORCYCLE) $cap = $cap * 0.5;

        $chargeMin = max(0, $minutes - 10);
        if ($lotCode === 'P03-EVENTOS') {
            $days = (int) ceil($minutes / (24 * 60));
            return min($cap * $days, $vehicleCode === VehicleType::CODE_CAR ? 18000 * $days : 12000 * $days);
        }

        $hourly = $vehicleCode === VehicleType::CODE_CAR ? 3500 : 1800;
        $hours = (int) ceil($chargeMin / 60);
        return min($hours * $hourly, $cap);
    }

    /* ------------------------------------------------------------------ */
    /*  Sesiones activas (vehiculos dentro ahora)                          */
    /* ------------------------------------------------------------------ */

    protected function createActiveSessions(): void
    {
        $alreadyActive = ParkingSession::query()
            ->where('company_id', $this->company->id)
            ->where('status', ParkingSession::STATUS_ACTIVE)
            ->count();
        if ($alreadyActive > 0) {
            $this->command->line("   · Sesiones activas: {$alreadyActive} ya existen.");
            return;
        }

        $active = [
            ['plate' => 'MQR123', 'lot' => 'P01-URBANO', 'vehicle' => VehicleType::CODE_CAR, 'mins_ago' => 87, 'membership_plate' => 'MQR123'],
            ['plate' => 'POG456', 'lot' => 'P01-URBANO', 'vehicle' => VehicleType::CODE_CAR, 'mins_ago' => 35, 'space' => 'C-04'],
            ['plate' => 'TYM45D', 'lot' => 'P01-URBANO', 'vehicle' => VehicleType::CODE_MOTORCYCLE, 'mins_ago' => 215, 'membership_plate' => 'TYM45D'],
            ['plate' => 'ACM003', 'lot' => 'P02-MALL', 'vehicle' => VehicleType::CODE_CAR, 'mins_ago' => 120, 'space' => 'A-05', 'membership_plate' => 'ACM003'],
            ['plate' => 'XKL789', 'lot' => 'P02-MALL', 'vehicle' => VehicleType::CODE_CAR, 'mins_ago' => 45, 'space' => 'B-02'],
            ['plate' => 'CIN001', 'lot' => 'P03-EVENTOS', 'vehicle' => VehicleType::CODE_CAR, 'mins_ago' => 60, 'membership_plate' => 'CIN001'],
            ['plate' => 'CIN007', 'lot' => 'P03-EVENTOS', 'vehicle' => VehicleType::CODE_CAR, 'mins_ago' => 10, 'membership_plate' => 'CIN007'],
        ];

        foreach ($active as $data) {
            $lot = $this->lots[$data['lot']];
            $vt = $this->vehicleTypes[$data['vehicle']];

            // Resolver mensualidad si la placa esta cubierta
            $membership = null;
            if (! empty($data['membership_plate'])) {
                $membership = ParkingMembership::query()
                    ->where('company_id', $this->company->id)
                    ->where('parking_lot_id', $lot->id)
                    ->where('status', ParkingMembership::STATUS_ACTIVE)
                    ->whereJsonContains('plates', $data['membership_plate'])
                    ->first();
            }

            // Resolver espacio si tiene codigo y ocuparlo
            $spaceId = null;
            $spaceCode = null;
            if (! empty($data['space'])) {
                $space = ParkingSpace::query()
                    ->where('parking_lot_id', $lot->id)
                    ->where('code', $data['space'])
                    ->where('status', ParkingSpace::STATUS_FREE)
                    ->first();
                if ($space) {
                    $space->update(['status' => ParkingSpace::STATUS_OCCUPIED]);
                    $spaceId = $space->id;
                    $spaceCode = $space->code;
                }
            }

            ParkingSession::create([
                'company_id' => $this->company->id,
                'parking_lot_id' => $lot->id,
                'vehicle_type_id' => $vt->id,
                'parking_space_id' => $spaceId,
                'parking_membership_id' => $membership?->id,
                'plate' => $data['plate'],
                'space_code' => $spaceCode,
                'entry_at' => now()->subMinutes($data['mins_ago']),
                'status' => ParkingSession::STATUS_ACTIVE,
                'created_by_user_id' => $this->admin->id,
            ]);
        }
        $this->command->line('   · Sesiones activas creadas: '.count($active));
    }

    /* ------------------------------------------------------------------ */
    /*  Incidentes                                                          */
    /* ------------------------------------------------------------------ */

    protected function createIncidents(): void
    {
        $incidents = [
            [
                'lot' => 'P02-MALL', 'kind' => ParkingIncident::KIND_DAMAGE,
                'severity' => ParkingIncident::SEVERITY_HIGH,
                'status' => ParkingIncident::STATUS_OPEN,
                'plate' => 'POG456',
                'description' => 'Cliente reporta rayón en puerta delantera derecha al recoger el vehículo. Solicita revisión de cámaras y reclamo a seguros.',
                'days_ago' => 1,
            ],
            [
                'lot' => 'P01-URBANO', 'kind' => ParkingIncident::KIND_IRREGULAR,
                'severity' => ParkingIncident::SEVERITY_MEDIUM,
                'status' => ParkingIncident::STATUS_INVESTIGATING,
                'plate' => null,
                'description' => 'Vehículo extraño merodeando entre carros parqueados durante la madrugada. Reportado por vigilante de turno. Revisar grabación 03:15-03:40 AM.',
                'days_ago' => 2,
            ],
            [
                'lot' => 'P03-EVENTOS', 'kind' => ParkingIncident::KIND_MAINTENANCE,
                'severity' => ParkingIncident::SEVERITY_LOW,
                'status' => ParkingIncident::STATUS_RESOLVED,
                'plate' => null,
                'description' => 'Lámpara fundida en zona Norte espacio N-08. Mantenimiento programado para reemplazo.',
                'resolved_notes' => 'Lámpara reemplazada por el equipo de mantenimiento.',
                'days_ago' => 5,
            ],
            [
                'lot' => 'P02-MALL', 'kind' => ParkingIncident::KIND_ACCIDENT,
                'severity' => ParkingIncident::SEVERITY_CRITICAL,
                'status' => ParkingIncident::STATUS_RESOLVED,
                'plate' => 'XYZ987',
                'description' => 'Colisión menor entre dos vehículos al entrar al sótano. Sin heridos. Se levantó parte de tránsito.',
                'resolved_notes' => 'Caso cerrado. Ambos conductores arreglaron amistosamente con seguros.',
                'days_ago' => 8,
            ],
        ];

        foreach ($incidents as $data) {
            $lot = $this->lots[$data['lot']];
            $createdAt = now()->subDays($data['days_ago']);
            $resolved = in_array($data['status'], [
                ParkingIncident::STATUS_RESOLVED,
                ParkingIncident::STATUS_DISMISSED,
            ], true);

            $exists = ParkingIncident::query()
                ->where('company_id', $this->company->id)
                ->where('parking_lot_id', $lot->id)
                ->where('description', $data['description'])
                ->first();
            if ($exists) continue;

            ParkingIncident::create([
                'company_id' => $this->company->id,
                'parking_lot_id' => $lot->id,
                'kind' => $data['kind'],
                'severity' => $data['severity'],
                'status' => $data['status'],
                'plate' => $data['plate'],
                'description' => $data['description'],
                'reported_by_user_id' => $this->admin->id,
                'resolved_by_user_id' => $resolved ? $this->admin->id : null,
                'resolved_at' => $resolved ? $createdAt->copy()->addDays(1) : null,
                'resolved_notes' => $data['resolved_notes'] ?? null,
                'created_at' => $createdAt,
                'updated_at' => $resolved ? $createdAt->copy()->addDays(1) : $createdAt,
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Genera placas colombianas pseudo-aleatorias unicas para el demo.
     * Carros: 3 letras + 3 digitos. Motos: 3 letras + 2 digitos + 1 letra.
     */
    protected function randomPlatePool(int $count): array
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // sin I/O para evitar ambiguedad
        $digits = '0123456789';
        $out = [];
        $seen = [];
        while (count($out) < $count) {
            $isMoto = random_int(1, 100) <= 25;
            $plate = '';
            for ($i = 0; $i < 3; $i++) $plate .= $letters[random_int(0, strlen($letters) - 1)];
            if ($isMoto) {
                for ($i = 0; $i < 2; $i++) $plate .= $digits[random_int(0, strlen($digits) - 1)];
                $plate .= $letters[random_int(0, strlen($letters) - 1)];
            } else {
                for ($i = 0; $i < 3; $i++) $plate .= $digits[random_int(0, strlen($digits) - 1)];
            }
            if (! isset($seen[$plate])) {
                $seen[$plate] = true;
                $out[] = $plate;
            }
        }
        return $out;
    }
}
