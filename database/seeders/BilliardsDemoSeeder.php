<?php

namespace Database\Seeders;

use App\Models\Billiards\BilliardHall;
use App\Models\Billiards\BilliardRate;
use App\Models\Billiards\BilliardSession;
use App\Models\Billiards\BilliardTable;
use App\Models\Billiards\BilliardTableType;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tax;
use App\Models\User;
use App\Services\Billiards\BilliardAccount;
use App\Services\Billiards\BilliardDefaultsProvisioner;
use App\Services\Billiards\BilliardProductProvisioner;
use App\Services\Billiards\BilliardSessionEngine;
use App\Services\Onboarding\CompanyOnboarding;
use App\Support\CompanyRoles;
use App\Support\CurrentCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Cuenta demo del módulo de billares, para mostrarle el sistema a un salón.
 *
 * IDEMPOTENTE: se puede correr muchas veces. Lo que ya existe se detecta por
 * código y no se duplica. Las cuentas históricas se generan relativas a
 * `now()`, así que cada corrida refresca las fechas y el reporte del mes
 * siempre tiene datos.
 *
 *   docker compose exec app php artisan db:seed --class=BilliardsDemoSeeder
 *
 * ---------------------------------------------------------------------
 * UNA DIFERENCIA A PROPÓSITO CON EL DEMO DE PARQUEADERO
 * ---------------------------------------------------------------------
 *
 * Aquel no crea resolución DIAN ni turno de caja, así que el prospecto que
 * da clic en «cobrar» se topa con un error en mitad de la demostración. Este
 * los crea: la idea de una demo es que el recorrido completo funcione a la
 * primera, incluido el cobro de verdad con su factura.
 *
 * ---------------------------------------------------------------------
 * LO QUE DEJA MONTADO
 * ---------------------------------------------------------------------
 *
 *   - Empresa «Billares La Esquina S.A.S.» con el módulo encendido
 *   - Dos usuarios, para enseñar que el cajero NO puede perdonar plata:
 *       admin@billareslaesquina.co   — ve y hace todo
 *       cajero@billareslaesquina.co  — opera, pero sin cortesías ni anular
 *   - Resolución POS y turno de caja abierto: el cobro funciona ya
 *   - Los 5 tipos de mesa, 12 mesas en 3 zonas
 *   - 6 tarifas que son el muestrario del motor: happy hour, tarifa de
 *     noche que cruza la medianoche, hora completa empezada, por partida,
 *     escalonada y una de torneo con vigencia
 *   - 8 productos de barra para cargar a las mesas
 *   - ~45 cuentas cerradas de los últimos 14 días, con su tiempo y sus
 *     consumos, calculadas con el motor de tarifas de verdad
 *   - 5 mesas abiertas ahora mismo, una de ellas en pausa
 */
class BilliardsDemoSeeder extends Seeder
{
    protected const PASSWORD = 'Demo2026!';

    protected Company $empresa;

    protected User $admin;

    protected User $cajero;

    protected Location $sede;

    protected BilliardHall $sala;

    /** @var array<string, BilliardTableType> */
    protected array $tipos = [];

    /** @var array<string, BilliardTable> */
    protected array $mesas = [];

    /** @var list<Product> */
    protected array $productos = [];

    public function run(): void
    {
        $this->command->info('🎱 Sembrando la demo de billares…');

        $this->creaLaEmpresa();
        $this->creaLosUsuarios();

        // Antes de tocar nada más: casi todo lo que sigue pasa por servicios
        // y modelos que leen el usuario y la empresa activos —para atribuir
        // quién hizo qué, y porque los modelos llevan un scope por empresa—.
        // Va antes del bootstrap porque ese ya crea la sede.
        Auth::login($this->admin);
        app(CurrentCompany::class)->set($this->empresa);

        $this->bootstrap();
        $this->aseguraLaSuscripcion();
        $this->creaLaResolucionPos();
        $this->creaLosTiposDeMesa();
        $this->creaLaSala();
        $this->creaLasMesas();
        $this->creaLasTarifas();
        $this->creaLosProductos();
        $this->abreLaCaja();
        $this->creaLasCuentasHistoricas();
        $this->abreMesasAhora();

        Auth::logout();

        $this->command->newLine();
        $this->command->info('✅ Demo de billares lista.');
        $this->command->line("   Empresa:  {$this->empresa->name}");
        $this->command->line("   Admin:    {$this->admin->email}");
        $this->command->line("   Cajero:   {$this->cajero->email}");
        $this->command->line('   Password: '.self::PASSWORD);
        $this->command->line('   Terminal: /app/billar');
    }

    /* ------------------------------------------------------------------ */
    /*  Empresa y usuarios                                                 */
    /* ------------------------------------------------------------------ */

    protected function creaLaEmpresa(): void
    {
        $this->empresa = Company::firstOrCreate(
            ['nit' => '901666777'],
            [
                'name' => 'Billares La Esquina S.A.S.',
                'legal_name' => 'Billares La Esquina Sociedad por Acciones Simplificada',
                'document_type' => 'nit',
                'organization_type' => 'juridica',
                'dv' => '1',
                'regime_type' => 'comun',
                'accounting_method' => 'niif_pymes',
                'inventory_method' => 'weighted_average',
                'address' => 'Calle 53 #24-18',
                'city' => 'Bogotá',
                'department' => 'Cundinamarca',
                'phone' => '6012223344',
                'phone_country_code' => '+57',
                'email' => 'contacto@billareslaesquina.co',
                'currency' => 'COP',
                'timezone' => 'America/Bogota',
                'active_modules' => ['billiards'],
                'active' => true,
            ],
        );

        // Si la empresa ya existía (demo vieja), igual se le enciende.
        $modulos = $this->empresa->active_modules ?? [];

        if (! in_array('billiards', $modulos, true)) {
            $modulos[] = 'billiards';
            $this->empresa->update(['active_modules' => array_values(array_unique($modulos))]);
        }
    }

    /**
     * Dos usuarios y no uno.
     *
     * La separación de permisos es de lo que más se pregunta en una demo
     * —«¿y el mesero puede regalar una mesa?»— y se enseña mucho mejor
     * entrando con el cajero que explicándola de palabra.
     */
    protected function creaLosUsuarios(): void
    {
        $this->admin = User::firstOrCreate(
            ['email' => 'admin@billareslaesquina.co'],
            [
                'company_id' => $this->empresa->id,
                'name' => 'Administrador',
                'last_name' => 'La Esquina',
                'password' => Hash::make(self::PASSWORD),
                'is_super_admin' => false,
                'active' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->cajero = User::firstOrCreate(
            ['email' => 'cajero@billareslaesquina.co'],
            [
                'company_id' => $this->empresa->id,
                'name' => 'Marcela',
                'last_name' => 'Mesera',
                'password' => Hash::make(self::PASSWORD),
                'is_super_admin' => false,
                'active' => true,
                'email_verified_at' => now(),
            ],
        );

        CompanyRoles::provision($this->empresa);

        try {
            CompanyRoles::assign($this->admin, 'admin');
            CompanyRoles::assign($this->cajero, 'cashier');
        } catch (\Throwable $e) {
            $this->command->warn('   · No se pudieron asignar roles: '.$e->getMessage());
        }
    }

    protected function bootstrap(): void
    {
        $resumen = app(CompanyOnboarding::class)->bootstrap($this->empresa);

        $this->command->line("   · Onboarding: PUC={$resumen['puc']}, impuestos={$resumen['taxes']}, "
            ."sede={$resumen['location']}, Consumidor Final={$resumen['consumer_final']}");

        $this->sede = Location::query()
            ->where('company_id', $this->empresa->id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->firstOrFail();
    }

    /**
     * Sin suscripción vigente NADIE puede entrar.
     *
     * `User::canAccessPanel()` exige que la empresa tenga una suscripción
     * activa, así que una demo sin ella se queda en un 403 en la puerta: el
     * usuario y la contraseña son correctos y aun así no entra. Lo
     * descubrió una prueba de otro módulo que eligió a este admin al azar,
     * no yo —la demo se entregó sin comprobar que se pudiera abrir, que era
     * justo lo único que había que comprobar—.
     *
     * Se le pone la prueba gratuita con un año de vigencia: es una demo, no
     * un cliente que paga, y un año evita que caduque sola en mitad de una
     * demostración.
     */
    protected function aseguraLaSuscripcion(): void
    {
        $vigente = Subscription::query()
            ->where('company_id', $this->empresa->id)
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->where('ends_at', '>=', now())
            ->exists();

        if ($vigente) {
            return;
        }

        $plan = Plan::query()->orderBy('id')->first();

        if (! $plan) {
            $this->command->warn('   · No hay planes en la base: corre PlansSeeder o la demo no podrá entrar.');

            return;
        }

        Subscription::create([
            'company_id' => $this->empresa->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->addYear()->endOfDay(),
            'billing_cycle' => 'yearly',
            'price_paid' => 0,
            'currency' => 'COP',
            'notes' => 'Cuenta de demostración.',
        ]);

        $this->command->line('   · Suscripción de demostración creada: la cuenta puede entrar.');
    }

    /**
     * Sin resolución no hay consecutivo, y sin consecutivo el primer cobro
     * de la demo falla. Es el error número uno de un salón recién montado y
     * no tiene por qué aparecer con un prospecto al frente.
     */
    protected function creaLaResolucionPos(): void
    {
        $resolucion = DB::table('dian_resolutions')
            ->where('company_id', $this->empresa->id)
            ->where('kind', 'pos')
            ->first();

        if (! $resolucion) {
            $id = DB::table('dian_resolutions')->insertGetId([
                'company_id' => $this->empresa->id,
                'document_type_id' => 1,
                'document_type_name' => 'Factura de venta',
                'kind' => 'pos',
                'prefix' => 'BE',
                'resolution_number' => '18764000000001',
                'resolution_date' => now()->subMonths(6)->toDateString(),
                'range_from' => 1,
                'range_to' => 999999,
                'date_from' => now()->subMonths(6)->toDateString(),
                'date_to' => now()->addMonths(18)->toDateString(),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $resolucion = (object) ['id' => $id];
        }

        $yaEnlazada = DB::table('dian_location_resolutions')
            ->where('location_id', $this->sede->id)
            ->where('dian_resolution_id', $resolucion->id)
            ->exists();

        if (! $yaEnlazada) {
            DB::table('dian_location_resolutions')->insert([
                'location_id' => $this->sede->id,
                'dian_resolution_id' => $resolucion->id,
                'current_consecutive' => 0,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->command->line('   · Resolución POS lista: el cobro funciona desde el primer clic.');
    }

    /* ------------------------------------------------------------------ */
    /*  Catálogo                                                           */
    /* ------------------------------------------------------------------ */

    protected function creaLosTiposDeMesa(): void
    {
        app(BilliardDefaultsProvisioner::class)->provision($this->empresa);

        foreach (BilliardTableType::query()->where('company_id', $this->empresa->id)->get() as $tipo) {
            $this->tipos[$tipo->code] = $tipo;
        }
    }

    protected function creaLaSala(): void
    {
        $this->sala = BilliardHall::firstOrCreate(
            ['company_id' => $this->empresa->id, 'code' => 'ESQUINA'],
            [
                'name' => 'Salón La Esquina',
                'address' => 'Calle 53 #24-18',
                'city' => 'Bogotá',
                'phone' => '6012223344',
                'default_location_id' => $this->sede->id,
                'default_invoice_kind' => 'pos',
                'active' => true,
            ],
        );

        if (! $this->sala->default_location_id) {
            $this->sala->update(['default_location_id' => $this->sede->id]);
        }
    }

    /**
     * Doce mesas en tres zonas.
     *
     * Las zonas no son decoración: el mapa del terminal agrupa por ellas, y
     * una demo con todas las mesas en un solo bloque no enseña que el salón
     * de un piso se puede mirar aparte del de otro.
     */
    protected function creaLasMesas(): void
    {
        $mesas = [
            ['1', 'Salón principal', BilliardTableType::CODE_POOL],
            ['2', 'Salón principal', BilliardTableType::CODE_POOL],
            ['3', 'Salón principal', BilliardTableType::CODE_POOL],
            ['4', 'Salón principal', BilliardTableType::CODE_POOL],
            ['5', 'Salón principal', BilliardTableType::CODE_TRES_BANDAS],
            ['6', 'Salón principal', BilliardTableType::CODE_TRES_BANDAS],
            ['VIP-1', 'Segundo piso', BilliardTableType::CODE_TRES_BANDAS],
            ['VIP-2', 'Segundo piso', BilliardTableType::CODE_POOL],
            ['M-1', 'Segundo piso', BilliardTableType::CODE_MINIPOOL],
            ['M-2', 'Segundo piso', BilliardTableType::CODE_MINIPOOL],
            ['R-1', 'Terraza', BilliardTableType::CODE_RANA],
            ['PP-1', 'Terraza', BilliardTableType::CODE_PINGPONG],
        ];

        $orden = 0;

        foreach ($mesas as [$codigo, $zona, $tipo]) {
            $mesa = BilliardTable::firstOrCreate(
                ['hall_id' => $this->sala->id, 'code' => $codigo],
                [
                    'company_id' => $this->empresa->id,
                    'table_type_id' => ($this->tipos[$tipo] ?? null)?->id,
                    'zone' => $zona,
                    'status' => BilliardTable::STATUS_FREE,
                    'sort_order' => $orden += 10,
                    'active' => true,
                ],
            );

            $this->mesas[$codigo] = $mesa;
        }

        // Una mesa fuera de servicio: enseña que el estado existe y que el
        // tile se ve distinto sin tener que borrar la mesa.
        $this->mesas['PP-1']->update([
            'status' => BilliardTable::STATUS_MAINTENANCE,
            'notes' => 'Red rota, se cambia el jueves.',
        ]);
    }

    /**
     * Seis tarifas que son el muestrario del motor.
     *
     * Están escogidas para que una demostración pueda enseñar, sin tocar
     * configuración, lo que de verdad diferencia a un billar: que la hora
     * del martes a las tres no vale lo que la del viernes a las diez.
     */
    protected function creaLasTarifas(): void
    {
        $tarifas = [
            [
                'tipo' => BilliardTableType::CODE_POOL,
                'name' => 'Pool · Por hora con happy hour',
                'kind' => BilliardRate::KIND_REGULAR,
                'priority' => 100,
                'config' => [
                    'type' => 'per_hour',
                    'amount' => 10000,
                    'rounding' => 'ceil',
                    'rounding_unit_min' => 15,
                    'minimum_minutes' => 30,
                    'schedules' => [
                        ['label' => 'Happy hour', 'days' => [1, 2, 3, 4], 'from' => '14:00', 'to' => '18:00', 'amount' => 6000],
                        ['label' => 'Noche', 'days' => [4, 5, 6], 'from' => '20:00', 'to' => '02:00', 'amount' => 14000],
                    ],
                ],
            ],
            [
                'tipo' => BilliardTableType::CODE_TRES_BANDAS,
                'name' => 'Tres bandas · Hora completa empezada',
                'kind' => BilliardRate::KIND_REGULAR,
                'priority' => 100,
                'config' => [
                    'type' => 'per_hour',
                    'amount' => 18000,
                    'rounding' => 'ceil',
                    'rounding_unit_min' => 60,
                    'schedules' => [
                        ['label' => 'Noche', 'days' => [4, 5, 6], 'from' => '20:00', 'to' => '02:00', 'amount' => 22000],
                    ],
                ],
            ],
            [
                'tipo' => BilliardTableType::CODE_MINIPOOL,
                'name' => 'Mini pool · Por hora',
                'kind' => BilliardRate::KIND_REGULAR,
                'priority' => 100,
                'config' => [
                    'type' => 'per_hour',
                    'amount' => 7000,
                    'rounding' => 'ceil',
                    'rounding_unit_min' => 15,
                    'free_minutes' => 10,
                ],
            ],
            [
                'tipo' => BilliardTableType::CODE_RANA,
                'name' => 'Rana · Por partida',
                'kind' => BilliardRate::KIND_REGULAR,
                'priority' => 100,
                'config' => [
                    'type' => 'per_game',
                    'amount' => 5000,
                ],
            ],
            [
                'tipo' => BilliardTableType::CODE_PINGPONG,
                'name' => 'Ping pong · Escalonada con tope',
                'kind' => BilliardRate::KIND_REGULAR,
                'priority' => 100,
                'config' => [
                    'type' => 'tiered',
                    'amount' => 6000,
                    'max_amount' => 40000,
                    'tiers' => [
                        ['from_min' => 0, 'to_min' => 60, 'type' => 'flat', 'amount' => 9000],
                        ['from_min' => 60, 'to_min' => null, 'type' => 'per_hour', 'amount' => 6000],
                    ],
                ],
            ],
            [
                // Sin tipo de mesa: aplica a toda la sala y le gana a las de
                // arriba por prioridad mientras esté vigente.
                'tipo' => null,
                'name' => 'Torneo de diciembre · Tarifa plana',
                'kind' => BilliardRate::KIND_EVENT,
                'priority' => 500,
                'valid_from' => now()->addMonth()->startOfMonth()->toDateString(),
                'valid_to' => now()->addMonth()->endOfMonth()->toDateString(),
                'config' => [
                    'type' => 'flat',
                    'amount' => 25000,
                ],
            ],
        ];

        foreach ($tarifas as $datos) {
            BilliardRate::firstOrCreate(
                [
                    'company_id' => $this->empresa->id,
                    'hall_id' => $this->sala->id,
                    'name' => $datos['name'],
                ],
                [
                    'table_type_id' => $datos['tipo'] ? ($this->tipos[$datos['tipo']] ?? null)?->id : null,
                    'kind' => $datos['kind'],
                    'config' => $datos['config'],
                    'valid_from' => $datos['valid_from'] ?? now()->subYear()->toDateString(),
                    'valid_to' => $datos['valid_to'] ?? null,
                    'priority' => $datos['priority'],
                    'active' => true,
                ],
            );
        }
    }

    /**
     * La barra.
     *
     * Un billar vive tanto de la nevera como de las mesas, y una demo sin
     * nada que cargar a la cuenta deja fuera la mitad del módulo. Sin
     * inventario: el prospecto todavía no cargó existencias y una venta que
     * falla por stock en la demostración no enseña nada.
     */
    protected function creaLosProductos(): void
    {
        $iva = Tax::query()
            ->where('company_id', $this->empresa->id)
            ->where('type', 'vat')
            ->where('rate', 19)
            ->where('is_active', true)
            ->value('id');

        $items = [
            ['BAR-CERV', 'Cerveza', 5000],
            ['BAR-CERVL', 'Cerveza litro', 9000],
            ['BAR-GASE', 'Gaseosa', 3500],
            ['BAR-AGUA', 'Agua', 2500],
            ['BAR-CIGA', 'Cigarrillo (unidad)', 1000],
            ['BAR-EMPA', 'Empanada', 3000],
            ['BAR-PAPAS', 'Paquete de papas', 2500],
            ['BAR-TACO', 'Alquiler de taco', 2000],
        ];

        foreach ($items as [$codigo, $nombre, $precio]) {
            $producto = Product::firstOrCreate(
                ['company_id' => $this->empresa->id, 'code' => $codigo],
                [
                    'name' => $nombre,
                    'type' => $codigo === 'BAR-TACO' ? 'service' : 'good',
                    'unit_of_measure' => $codigo === 'BAR-TACO' ? 'service' : 'unit',
                    'track_inventory' => false,
                    'tracks_serials' => false,
                    'is_purchasable' => $codigo !== 'BAR-TACO',
                    'is_sellable' => true,
                    'default_sale_price' => $precio,
                    'sale_price_includes_tax' => false,
                    'default_sale_tax_id' => $iva,
                    'active' => true,
                ],
            );

            $this->productos[] = $producto;
        }

        // El de alquiler de mesa, que es el que usa la línea de tiempo.
        app(BilliardProductProvisioner::class)->ensure($this->empresa);
    }

    /**
     * Un turno abierto, para que «cobrar» funcione desde el primer clic.
     *
     * Se abre con la fecha de hoy aunque el seeder haya corrido antes: un
     * turno abierto hace tres semanas deja un arqueo absurdo en la demo.
     */
    protected function abreLaCaja(): void
    {
        $abierto = CashRegisterSession::query()
            ->where('company_id', $this->empresa->id)
            ->where('cashier_user_id', $this->admin->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->first();

        if ($abierto && $abierto->opened_at?->isToday()) {
            return;
        }

        if ($abierto) {
            $abierto->update([
                'status' => CashRegisterSession::STATUS_CLOSED,
                'closed_at' => $abierto->opened_at?->copy()->endOfDay() ?? now(),
            ]);
        }

        CashRegisterSession::create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->admin->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now()->startOfDay()->addHours(14),
            'opening_amount' => 100000,
            'opening_notes' => 'Base del turno de la tarde.',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Historia y mesas abiertas                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Cuentas cerradas de los últimos catorce días.
     *
     * Pasan por el motor de sesiones de verdad —no se escriben a mano— así
     * que el tiempo lo liquida la misma tarifa que vería el salón, con sus
     * franjas y su redondeo. Los números de la demo son los números reales
     * del sistema, que es lo único que hace que una demo sirva de algo.
     */
    protected function creaLasCuentasHistoricas(): void
    {
        $yaHay = BilliardSession::query()
            ->where('company_id', $this->empresa->id)
            ->where('status', BilliardSession::STATUS_CLOSED)
            ->count();

        if ($yaHay >= 40) {
            $this->command->line("   · Cuentas históricas: ya hay {$yaHay}, no vuelvo a generarlas.");

            return;
        }

        $nombres = [
            'Don Jorge', 'Los del torneo', 'Mesa de Andrés', 'Los pelados',
            'Doña Marta', 'Los de la empresa', 'Camilo y amigos', null,
            'Los de siempre', 'El combo del barrio', null, 'Familia Ruiz',
        ];

        $motor = app(BilliardSessionEngine::class);
        $cuenta = app(BilliardAccount::class);
        $creadas = 0;

        $jugables = collect($this->mesas)
            ->filter(fn (BilliardTable $m) => $m->status !== BilliardTable::STATUS_MAINTENANCE)
            ->values();

        for ($i = 0; $i < 45; $i++) {
            $mesa = $jugables->random();

            // Una noche de billar se concentra en la tarde y la noche; a las
            // ocho de la mañana no hay nadie jugando pool.
            $inicio = now()
                ->subDays(random_int(0, 13))
                ->setTime(random_int(14, 22), [0, 15, 30, 45][random_int(0, 3)]);

            // Un reparto parecido al de un salón: la mayoría entre tres
            // cuartos de hora y tres horas, unas pocas partidas cortas y
            // alguna noche larga.
            $dado = random_int(1, 100);
            $minutos = match (true) {
                $dado <= 10 => random_int(20, 45),
                $dado >= 91 => random_int(180, 330),
                default => random_int(45, 180),
            };

            $fin = $inicio->copy()->addMinutes($minutos);

            $sesion = BilliardSession::create([
                'company_id' => $this->empresa->id,
                'hall_id' => $this->sala->id,
                'table_id' => $mesa->id,
                'table_type_id' => $mesa->table_type_id,
                'rate_id' => app(\App\Services\Billiards\BilliardRateEngine::class)
                    ->resolveFor($mesa, $inicio)?->id,
                'customer_label' => $nombres[array_rand($nombres)],
                'players' => random_int(2, 6),
                'games' => random_int(0, 6),
                'started_at' => $inicio,
                'time_anchor_at' => $inicio,
                'status' => BilliardSession::STATUS_OPEN,
                'created_by_user_id' => $this->admin->id,
            ]);

            // Los consumos: de cero a cinco rondas. Una mesa sin nada de la
            // barra existe, pero es la minoría.
            foreach (range(1, random_int(0, 5)) as $ignorado) {
                $producto = $this->productos[array_rand($this->productos)];

                try {
                    $cuenta->agregarConsumo($sesion, [
                        'product_id' => $producto->id,
                        'quantity' => random_int(1, 4),
                    ]);
                } catch (\Throwable) {
                    // Un consumo que no entra no puede tumbar la demo entera.
                }
            }

            try {
                $motor->cerrar($sesion->fresh(['pauses', 'table', 'tableType']), $fin);
                $creadas++;
            } catch (\Throwable $e) {
                $this->command->warn('   · Una cuenta histórica no se pudo cerrar: '.$e->getMessage());
            }
        }

        // `cerrar()` libera la mesa, pero si algo falló a mitad de camino
        // podría quedar alguna marcada ocupada sin cuenta. El mapa del
        // terminal es lo primero que ve el prospecto.
        BilliardTable::query()
            ->where('hall_id', $this->sala->id)
            ->where('status', BilliardTable::STATUS_OCCUPIED)
            ->whereDoesntHave('openSessions')
            ->update(['status' => BilliardTable::STATUS_FREE]);

        $this->command->line("   · Cuentas históricas creadas: {$creadas}");
    }

    /**
     * Cinco mesas jugando ahora mismo, una en pausa.
     *
     * Es lo primero que se ve al entrar al terminal. Un mapa todo verde no
     * enseña nada: no hay reloj corriendo, ni cuenta que mirar, ni pausa
     * que explicar.
     */
    protected function abreMesasAhora(): void
    {
        $abiertas = BilliardSession::query()
            ->where('company_id', $this->empresa->id)
            ->where('status', BilliardSession::STATUS_OPEN)
            ->count();

        if ($abiertas >= 4) {
            $this->command->line("   · Mesas abiertas: ya hay {$abiertas}, no abro más.");

            return;
        }

        $motor = app(BilliardSessionEngine::class);
        $cuenta = app(BilliardAccount::class);

        $plan = [
            ['1', 'Don Jorge', 4, 95, false],
            ['3', 'Los del torneo', 6, 40, false],
            ['5', 'Camilo y amigos', 2, 150, false],
            ['VIP-1', 'Los de la empresa', 4, 25, false],
            // Esta se pausa: se fueron a comer y el reloj quedó quieto.
            ['M-1', 'Los pelados', 3, 70, true],
        ];

        foreach ($plan as [$codigo, $nombre, $jugadores, $minutos, $sePausa]) {
            $mesa = $this->mesas[$codigo] ?? null;

            if (! $mesa || ! $mesa->fresh()->isFree()) {
                continue;
            }

            try {
                $sesion = $motor->abrir([
                    'table_id' => $mesa->id,
                    'players' => $jugadores,
                    'customer_label' => $nombre,
                    // El reloj arranca atrás para que el contador del mapa ya
                    // muestre tiempo corrido al abrir la pantalla.
                    'started_at' => now()->subMinutes($minutos),
                ]);
            } catch (\Throwable $e) {
                $this->command->warn("   · No se pudo abrir la mesa {$codigo}: ".$e->getMessage());

                continue;
            }

            foreach (range(1, random_int(1, 4)) as $ignorado) {
                $producto = $this->productos[array_rand($this->productos)];

                try {
                    $cuenta->agregarConsumo($sesion, [
                        'product_id' => $producto->id,
                        'quantity' => random_int(1, 3),
                    ]);
                } catch (\Throwable) {
                }
            }

            if ($sePausa) {
                try {
                    $motor->pausar($sesion->fresh(['pauses']), 'Se fueron a comer');
                } catch (\Throwable $e) {
                    $this->command->warn('   · No se pudo pausar la mesa: '.$e->getMessage());
                }
            }
        }
    }
}
