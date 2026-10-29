<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Dian\LocationResolution;
use App\Models\Dian\Resolution;
use App\Models\Hotel\Guest;
use App\Models\Hotel\Hotel;
use App\Models\Hotel\HotelZone;
use App\Models\Hotel\Reservation;
use App\Models\Hotel\Room;
use App\Models\Hotel\RoomType;
use App\Models\Hotel\Stay;
use App\Models\Hotel\StayCharge;
use App\Models\Location;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tax;
use App\Models\User;
use App\Services\Hotel\HotelFolio;
use App\Services\Hotel\HotelProductProvisioner;
use App\Services\Hotel\HotelReservationEngine;
use App\Services\Hotel\HotelStayEngine;
use App\Services\Inventory\InventoryEngine;
use App\Services\Onboarding\CompanyOnboarding;
use App\Support\CompanyRoles;
use App\Support\CurrentCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Cuenta demo del módulo Hoteles, para mostrarle el sistema al equipo y a los
 * clientes.
 *
 * IDEMPOTENTE: se puede correr varias veces. Detecta por código lo que ya
 * existe y solo crea lo que falta. Lo que depende de la fecha —la historia, las
 * estadías abiertas, las llegadas de hoy— se genera relativo a `now()`, así que
 * cada corrida lo deja fresco si todavía no se había generado.
 *
 *   php artisan db:seed --class=HotelDemoSeeder
 *
 * Deja montado:
 *
 *   Empresa      Hotel Mirador del Parque S.A.S. (NIT 901777666)
 *   Admin        demo@hotelmirador.co      · Demo2026!
 *   Recepción    recepcion@hotelmirador.co · Demo2026!
 *
 * Los dos usuarios son a propósito: la diferencia entre lo que puede un
 * administrador y lo que puede un recepcionista es de las cosas que más se
 * preguntan en una demostración, y se ve mejor entrando con cada uno que
 * explicándola.
 *
 *   · 1 hotel con RNT, 4 zonas, 5 tipos de habitación y 24 habitaciones
 *   · Resolución POS y turno de caja abierto: el check-out se puede hacer
 *     en vivo durante la demostración
 *   · Minibar con inventario y servicios para cargar al folio
 *   · 14 huéspedes, 3 de ellos extranjeros
 *   · ~45 estadías cerradas de los últimos 60 días: es lo que le da contenido
 *     al reporte de ocupación, ADR y RevPAR
 *   · 6 habitaciones ocupadas ahora, con folios en distinto estado
 *   · Reservas en todos los estados, incluidas 3 llegadas para hoy
 *   · 2 habitaciones en aseo y 1 en mantenimiento
 */
class HotelDemoSeeder extends Seeder
{
    private const NIT = '901777666';

    /**
     * Las habitaciones que quedan ocupadas al final, con su caso de demo.
     *
     * La historia NO las toca. Tiene que ser así: la historia se genera antes
     * y ocupa habitaciones hasta hace pocos días, de modo que al llegar el
     * check-in de la 401 la disponibilidad —correctamente— lo rechazaba por
     * solaparse con una estadía de la semana pasada. El resultado era un
     * resumen que prometía «clic en la 401, se pasó de la fecha» sobre una
     * habitación vacía.
     */
    private const HABITACIONES_DE_LA_DEMO = ['103', '201', '206', '301', '401', '402'];

    /**
     * La contraseña de la cuenta demo.
     *
     * Configurable por entorno porque esta cuenta termina viviendo en la base
     * de producción —es ahí donde se le muestra a un cliente— y su contraseña
     * está escrita en un repositorio. No puede ver datos de otras empresas
     * (el aislamiento por empresa lo impide), pero sí se le puede entrar y
     * desordenar la demostración justo antes de una reunión.
     *
     *   HOTEL_DEMO_PASSWORD='algo-propio' php artisan db:seed --class=HotelDemoSeeder
     */
    private function password(): string
    {
        return (string) env('HOTEL_DEMO_PASSWORD', 'Demo2026!');
    }

    protected Company $company;

    protected User $admin;

    protected User $recepcion;

    protected Location $sede;

    protected Hotel $hotel;

    /** @var array<string, HotelZone> */
    protected array $zonas = [];

    /** @var array<string, RoomType> */
    protected array $tipos = [];

    /** @var array<string, Product> */
    protected array $productos = [];

    public function run(): void
    {
        $this->command->info('🏨  Montando la demo de Hoteles…');

        $this->aseguraLaEmpresa();
        $this->aseguraLosUsuarios();
        $this->aseguraLaSuscripcion();
        $this->bootstrapContable();

        // A partir de aquí el código de la aplicación corre como el
        // recepcionista: los motores guardan `created_by_user_id` con
        // `Auth::id()`, y sin sesión la historia quedaría sin autor.
        Auth::login($this->recepcion);
        app(CurrentCompany::class)->set($this->company);

        $this->aseguraResolucionPos();
        $this->creaElHotel();
        $this->creaLasZonas();
        $this->creaLosTipos();
        $this->creaLasHabitaciones();
        $this->creaElMinibar();
        $this->creaLosHuespedes();
        $this->creaLaHistoria();
        $this->creaLasEstadiasAbiertas();
        $this->creaLasReservas();
        $this->ponHabitacionesEnAseo();
        $this->abreElTurnoDeCaja();

        Auth::logout();

        $this->resumen();
    }

    /* ------------------------------------------------------------------ */
    /*  Empresa y usuarios */
    /* ------------------------------------------------------------------ */

    protected function aseguraLaEmpresa(): void
    {
        $this->company = Company::firstOrCreate(
            ['nit' => self::NIT],
            [
                'name' => 'Hotel Mirador del Parque S.A.S.',
                'legal_name' => 'Hotel Mirador del Parque Sociedad por Acciones Simplificada',
                'document_type' => 'nit',
                'organization_type' => 'juridica',
                'dv' => '1',
                'regime_type' => 'comun',
                'accounting_method' => 'niif_pymes',
                'inventory_method' => 'weighted_average',
                'address' => 'Calle 93 #13-45',
                'city' => 'Bogotá',
                'department' => 'Cundinamarca',
                'phone' => '6012223344',
                'phone_country_code' => '+57',
                'email' => 'reservas@hotelmirador.co',
                'currency' => 'COP',
                'timezone' => 'America/Bogota',
                'active_modules' => ['hotel', 'accounting'],
                'active' => true,
            ],
        );

        // El módulo tiene que quedar activo aunque la empresa ya existiera.
        $modulos = $this->company->active_modules ?? [];

        if (! in_array('hotel', $modulos, true)) {
            $modulos[] = 'hotel';
            $this->company->update(['active_modules' => array_values(array_unique($modulos))]);
        }

        // Configuración del módulo, con el pie de la cuenta puesto: en una
        // demostración se nota si el documento sale sin cerrar.
        $settings = $this->company->settings ?? [];
        $settings['hotel'] = array_merge([
            'charge_late_checkout' => true,
            'late_checkout_grace_minutes' => 60,
            'show_rnt' => true,
            'require_guest_document' => true,
            'folio_footer' => "Gracias por alojarse con nosotros.\nWiFi: MIRADOR-HUESPEDES",
        ], $settings['hotel'] ?? []);

        $this->company->update(['settings' => $settings]);
        $this->command->line("   · Empresa: {$this->company->name}");
    }

    protected function aseguraLosUsuarios(): void
    {
        $this->admin = User::firstOrCreate(
            ['email' => 'demo@hotelmirador.co'],
            [
                'company_id' => $this->company->id,
                'name' => 'Camila',
                'last_name' => 'Restrepo',
                'password' => Hash::make($this->password()),
                'is_super_admin' => false,
                'active' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->recepcion = User::firstOrCreate(
            ['email' => 'recepcion@hotelmirador.co'],
            [
                'company_id' => $this->company->id,
                'name' => 'Andrés',
                'last_name' => 'Molina',
                'password' => Hash::make($this->password()),
                'is_super_admin' => false,
                'active' => true,
                'email_verified_at' => now(),
            ],
        );

        CompanyRoles::provision($this->company);
        CompanyRoles::assign($this->admin, 'admin');
        CompanyRoles::assign($this->recepcion, 'cashier');

        $this->command->line('   · Usuarios: administrador y recepción');
    }

    /**
     * Suscripción activa, o la cuenta demo no puede entrar.
     *
     * `canAccessPanel()` bloquea el acceso al panel de una empresa sin
     * suscripción vigente, y el formulario de login responde lo mismo que
     * ante una contraseña mala. Sin esto, la demo queda perfecta por dentro y
     * la contraseña «no funciona»: una hora buscando el error donde no está.
     */
    protected function aseguraLaSuscripcion(): void
    {
        if (Subscription::query()->where('company_id', $this->company->id)->exists()) {
            return;
        }

        $plan = Plan::query()->where('slug', 'free-trial')->first()
            ?? Plan::query()->where('is_active', true)->orderBy('sort_order')->first();

        if (! $plan) {
            $this->command->warn(
                '   · No hay planes en la base: la empresa queda sin suscripción y NO podrá entrar. '
                .'Corre primero: php artisan db:seed --class=PlansSeeder'
            );

            return;
        }

        Subscription::create([
            'company_id' => $this->company->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_TRIAL,
            'starts_at' => now(),
            'ends_at' => now()->addYear(),
            'price_paid' => 0,
            'currency' => $plan->currency,
            'billing_cycle' => $plan->billing_cycle,
            'created_by_user_id' => $this->admin->id,
            'notes' => 'Cuenta demo del módulo Hoteles.',
        ]);

        $this->command->line("   · Suscripción de demostración creada (plan {$plan->name})");
    }

    protected function bootstrapContable(): void
    {
        $resumen = app(CompanyOnboarding::class)->bootstrap($this->company);

        $this->sede = Location::query()
            ->where('company_id', $this->company->id)
            ->where('active', true)
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->firstOrFail();

        $this->command->line(
            "   · Contabilidad: PUC={$resumen['puc']}, impuestos={$resumen['taxes']}, "
            ."sede={$resumen['location']}"
        );
    }

    /**
     * Resolución POS para la sede.
     *
     * Sin ella el check-out revienta justo en el momento del cobro, que es
     * exactamente donde no se quiere que falle una demostración.
     */
    protected function aseguraResolucionPos(): void
    {
        // El nombre NO se escribe a mano: sale del catálogo del modelo, que
        // son los códigos oficiales de la DIAN. Escribirlo a mano es cómo
        // quedó aquí «Factura de Venta» para el tipo 1, que para la DIAN es
        // «Factura Electrónica». Un nombre que no corresponde al código es
        // justo el desajuste que costó cuatro rondas de depuración con el
        // proveedor tecnológico —y que `DianDocumentTypeCodesTest` existe
        // para impedir; esta fila se quedaba en la base y la prueba la cazó—.
        $tipoFactura = 1;

        $resolucion = Resolution::firstOrCreate(
            ['company_id' => $this->company->id, 'prefix' => 'DEMO'],
            [
                'kind' => Resolution::KIND_POS,
                'document_type_id' => $tipoFactura,
                'document_type_name' => Resolution::DOCUMENT_TYPES[$tipoFactura],
                'resolution_number' => 'DEMO-POS-2026',
                'range_from' => 1,
                'range_to' => 999999,
                'active' => true,
            ],
        );

        LocationResolution::firstOrCreate(
            ['location_id' => $this->sede->id, 'dian_resolution_id' => $resolucion->id],
            ['current_consecutive' => 0, 'active' => true],
        );

        $this->command->line('   · Resolución POS lista: el check-out se puede cobrar en vivo');
    }

    /* ------------------------------------------------------------------ */
    /*  El hotel */
    /* ------------------------------------------------------------------ */

    protected function creaElHotel(): void
    {
        $this->hotel = Hotel::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'MIRADOR'],
            [
                'name' => 'Hotel Mirador del Parque',
                'address' => 'Calle 93 #13-45',
                'city' => 'Bogotá',
                'phone' => '6012223344',
                'email' => 'reservas@hotelmirador.co',
                'rnt' => '98765',
                'default_location_id' => $this->sede->id,
                'default_invoice_kind' => 'pos',
                'check_in_time' => '15:00:00',
                'check_out_time' => '12:00:00',
                'active' => true,
            ],
        );

        if (! $this->hotel->default_location_id) {
            $this->hotel->update(['default_location_id' => $this->sede->id]);
        }
    }

    protected function creaLasZonas(): void
    {
        $zonas = [
            ['name' => 'Piso 1', 'code' => 'P1', 'color' => '#0ea5e9', 'display_order' => 10],
            ['name' => 'Piso 2', 'code' => 'P2', 'color' => '#6366f1', 'display_order' => 20],
            ['name' => 'Piso 3', 'code' => 'P3', 'color' => '#8b5cf6', 'display_order' => 30],
            ['name' => 'Terraza', 'code' => 'TZ', 'color' => '#16a34a', 'display_order' => 40],
        ];

        foreach ($zonas as $datos) {
            $this->zonas[$datos['name']] = HotelZone::firstOrCreate(
                ['hotel_id' => $this->hotel->id, 'name' => $datos['name']],
                array_merge($datos, [
                    'company_id' => $this->company->id,
                    'hotel_id' => $this->hotel->id,
                    'active' => true,
                ]),
            );
        }
    }

    /**
     * Tipos con tarifa de verdad.
     *
     * El provisionador de defaults los crea en $0 a propósito, porque una
     * tarifa inventada se queda puesta y algún día se le cobra a un huésped.
     * En la demo sí hay que ponerles precio: con tarifa en cero el sistema no
     * deja alojar a nadie, que es justo lo que se quiere mostrar funcionando.
     */
    protected function creaLosTipos(): void
    {
        $tipos = [
            [
                'code' => 'SEN', 'name' => 'Sencilla', 'sort_order' => 10,
                'max_adults' => 1, 'max_children' => 0, 'beds' => '1 sencilla',
                'base_rate' => 145000, 'extra_person_rate' => 0,
                'description' => 'Habitación individual con baño privado, escritorio y WiFi.',
                'amenities' => ['wifi', 'tv', 'baño privado', 'escritorio'],
            ],
            [
                'code' => 'DOB', 'name' => 'Doble', 'sort_order' => 20,
                'max_adults' => 2, 'max_children' => 1, 'beds' => '1 doble',
                'base_rate' => 198000, 'extra_person_rate' => 45000,
                'description' => 'Cama doble, aire acondicionado y vista interior.',
                'amenities' => ['wifi', 'tv', 'aire', 'caja fuerte'],
            ],
            [
                'code' => 'TWIN', 'name' => 'Doble con dos camas', 'sort_order' => 30,
                'max_adults' => 2, 'max_children' => 1, 'beds' => '2 sencillas',
                'base_rate' => 205000, 'extra_person_rate' => 45000,
                'description' => 'Dos camas sencillas, ideal para viajeros de negocios.',
                'amenities' => ['wifi', 'tv', 'aire', 'escritorio'],
            ],
            [
                'code' => 'FAM', 'name' => 'Familiar', 'sort_order' => 40,
                'max_adults' => 4, 'max_children' => 2, 'beds' => '2 dobles',
                'base_rate' => 320000, 'extra_person_rate' => 50000,
                'description' => 'Dos camas dobles, nevera y sala de estar.',
                'amenities' => ['wifi', 'tv', 'aire', 'nevera', 'sala'],
            ],
            [
                'code' => 'SUITE', 'name' => 'Suite Mirador', 'sort_order' => 50,
                'max_adults' => 2, 'max_children' => 2, 'beds' => '1 king',
                'base_rate' => 465000, 'extra_person_rate' => 60000,
                'description' => 'Cama king, jacuzzi y terraza con vista al parque.',
                'amenities' => ['wifi', 'tv', 'aire', 'jacuzzi', 'terraza', 'minibar'],
            ],
        ];

        foreach ($tipos as $datos) {
            $tipo = RoomType::firstOrCreate(
                ['hotel_id' => $this->hotel->id, 'code' => $datos['code']],
                array_merge($datos, [
                    'company_id' => $this->company->id,
                    'hotel_id' => $this->hotel->id,
                    'active' => true,
                ]),
            );

            // Si el tipo venía del provisionador en $0, se le pone la tarifa.
            if ((float) $tipo->base_rate <= 0) {
                $tipo->update([
                    'base_rate' => $datos['base_rate'],
                    'extra_person_rate' => $datos['extra_person_rate'],
                    'description' => $datos['description'],
                    'beds' => $datos['beds'],
                    'amenities' => $datos['amenities'],
                ]);
            }

            $this->tipos[$datos['code']] = $tipo->fresh();
        }
    }

    protected function creaLasHabitaciones(): void
    {
        // Piso, número, tipo. Mezclados a propósito para que el mapa se vea
        // como un hotel y no como una tabla.
        $plano = [
            ['Piso 1', '101', 'SEN'], ['Piso 1', '102', 'SEN'], ['Piso 1', '103', 'DOB'],
            ['Piso 1', '104', 'DOB'], ['Piso 1', '105', 'TWIN'], ['Piso 1', '106', 'FAM'],
            ['Piso 2', '201', 'DOB'], ['Piso 2', '202', 'DOB'], ['Piso 2', '203', 'TWIN'],
            ['Piso 2', '204', 'TWIN'], ['Piso 2', '205', 'DOB'], ['Piso 2', '206', 'FAM'],
            ['Piso 2', '207', 'SEN'], ['Piso 2', '208', 'DOB'],
            ['Piso 3', '301', 'DOB'], ['Piso 3', '302', 'TWIN'], ['Piso 3', '303', 'FAM'],
            ['Piso 3', '304', 'DOB'], ['Piso 3', '305', 'SEN'], ['Piso 3', '306', 'DOB'],
            ['Terraza', '401', 'SUITE'], ['Terraza', '402', 'SUITE'],
            ['Terraza', '403', 'SUITE'], ['Terraza', '404', 'FAM'],
        ];

        $creadas = 0;

        foreach ($plano as [$zona, $numero, $tipo]) {
            $existe = Room::query()
                ->where('hotel_id', $this->hotel->id)
                ->where('number', $numero)
                ->exists();

            if ($existe) {
                continue;
            }

            Room::create([
                'company_id' => $this->company->id,
                'hotel_id' => $this->hotel->id,
                'zone_id' => $this->zonas[$zona]->id,
                'room_type_id' => $this->tipos[$tipo]->id,
                'number' => $numero,
                'name' => $tipo === 'SUITE' ? 'Vista al parque' : null,
                'floor' => $zona,
                'status' => Room::STATUS_AVAILABLE,
                'max_occupancy' => $this->tipos[$tipo]->capacidad(),
                'active' => true,
            ]);

            $creadas++;
        }

        $total = Room::query()->where('hotel_id', $this->hotel->id)->count();
        $this->command->line("   · Habitaciones: {$creadas} creadas, {$total} en total");
    }

    /**
     * Lo que se carga al folio: el minibar y los servicios.
     *
     * El minibar lleva inventario con una entrada inicial, para que la
     * demostración muestre que un consumo descarga el almacén de verdad. Los
     * servicios no: una lavandería no tiene existencias.
     */
    protected function creaElMinibar(): void
    {
        $iva = Tax::query()
            ->where('company_id', $this->company->id)
            ->where('type', 'vat')
            ->where('rate', 19)
            ->where('is_active', true)
            ->value('id');

        $cuentaVenta = Account::query()
            ->where('company_id', $this->company->id)
            ->where('code', '4140')
            ->where('accepts_movements', true)
            ->value('id');

        $catalogo = [
            // código, nombre, precio al público, inventario
            ['MB-AGUA', 'Agua en botella 600 ml', 5000, true],
            ['MB-CERV', 'Cerveza nacional 330 ml', 9000, true],
            ['MB-GASE', 'Gaseosa 400 ml', 6000, true],
            ['MB-SNACK', 'Snack de maní', 7000, true],
            ['SV-LAVAN', 'Lavandería (por prenda)', 12000, false],
            ['SV-DESAY', 'Desayuno adicional', 24000, false],
            ['SV-PARQ', 'Parqueadero por noche', 18000, false],
            ['SV-SALON', 'Uso de salón de reuniones (hora)', 85000, false],
        ];

        foreach ($catalogo as [$codigo, $nombre, $precio, $conInventario]) {
            $producto = Product::firstOrCreate(
                ['company_id' => $this->company->id, 'code' => $codigo],
                [
                    'name' => $nombre,
                    'type' => $conInventario ? 'good' : 'service',
                    'unit_of_measure' => $conInventario ? 'unit' : 'service',
                    'track_inventory' => $conInventario,
                    'tracks_serials' => false,
                    'is_purchasable' => $conInventario,
                    'is_sellable' => true,
                    'default_sale_price' => $precio,
                    'sale_price_includes_tax' => true,
                    'default_sale_tax_id' => $iva,
                    'sale_account_id' => $cuentaVenta,
                    'active' => true,
                ],
            );

            $this->productos[$codigo] = $producto;

            if (! $conInventario || ! $producto->wasRecentlyCreated) {
                continue;
            }

            // Entrada inicial para que el minibar tenga de qué descontar.
            // Si el motor contable rechaza algo, la demo no se cae por eso.
            try {
                app(InventoryEngine::class)->addMovement($producto, $this->sede, [
                    'type' => 'opening',
                    'quantity' => 120,
                    'unit_cost' => round($precio / 1.19 * 0.45, 2),
                    'date' => now()->subDays(60)->toDateString(),
                    'description' => 'Carga inicial del minibar (demo)',
                ]);
            } catch (\Throwable $e) {
                $this->command->warn("   · Sin inventario inicial para {$codigo}: {$e->getMessage()}");
            }
        }

        // El producto de alojamiento, que es el que factura las noches.
        app(HotelProductProvisioner::class)->ensure($this->company);

        $this->command->line('   · Minibar y servicios listos para cargar al folio');
    }

    /* ------------------------------------------------------------------ */
    /*  Huéspedes */
    /* ------------------------------------------------------------------ */

    /** @return array<int, Guest> */
    protected function creaLosHuespedes(): array
    {
        $gente = [
            ['cc', '1020304050', 'María Fernanda', 'Gómez Ruiz', 'Colombiana', 'Medellín', 'Bogotá', 'leisure'],
            ['cc', '79456123', 'Jorge Enrique', 'Patiño Vélez', 'Colombiana', 'Cali', 'Bogotá', 'business'],
            ['cc', '52789456', 'Claudia Patricia', 'Ramírez Soto', 'Colombiana', 'Barranquilla', 'Bogotá', 'business'],
            ['cc', '1098765432', 'Daniel', 'Ospina Mejía', 'Colombiana', 'Pereira', 'Bogotá', 'leisure'],
            ['cc', '43215678', 'Luz Adriana', 'Cardona Pérez', 'Colombiana', 'Manizales', 'Bogotá', 'health'],
            ['cc', '80123456', 'Camilo', 'Herrera Nieto', 'Colombiana', 'Bucaramanga', 'Bogotá', 'business'],
            ['cc', '1032145698', 'Sofía', 'Lozano Arias', 'Colombiana', 'Villavicencio', 'Bogotá', 'study'],
            ['cc', '71234567', 'Óscar Iván', 'Betancur Gil', 'Colombiana', 'Armenia', 'Bogotá', 'family'],
            ['cc', '53987456', 'Natalia', 'Sierra Quintero', 'Colombiana', 'Cartagena', 'Bogotá', 'leisure'],
            ['ti', '1145678932', 'Mateo', 'Betancur Sierra', 'Colombiana', 'Armenia', 'Bogotá', 'family'],
            // Extranjeros: los que pueden ir exentos de IVA (art. 481 ET) y
            // los que hay que poder reportar a Migración.
            ['pa', 'AB1234567', 'John', 'Mitchell', 'Estadounidense', 'Miami', 'Bogotá', 'leisure'],
            ['pa', 'FR9988776', 'Camille', 'Dubois', 'Francesa', 'París', 'Cartagena', 'leisure'],
            ['pa', 'ES4455667', 'Álvaro', 'Serrano Ortiz', 'Española', 'Madrid', 'Bogotá', 'business'],
            ['ce', '5566778', 'Ana Lucía', 'Morales Quiroz', 'Venezolana', 'Caracas', 'Bogotá', 'business'],
        ];

        $huespedes = [];

        foreach ($gente as $i => [$tipoDoc, $doc, $nombres, $apellidos, $nacionalidad, $origen, $destino, $motivo]) {
            $huespedes[] = Guest::firstOrCreate(
                [
                    'company_id' => $this->company->id,
                    'document_type' => $tipoDoc,
                    'document_number' => $doc,
                ],
                [
                    'first_name' => $nombres,
                    'last_name' => $apellidos,
                    'nationality' => $nacionalidad,
                    'birth_date' => now()->subYears(random_int(22, 62))->subDays(random_int(0, 364))->toDateString(),
                    'phone' => '3'.random_int(100000000, 199999999),
                    'email' => strtolower(str_replace(' ', '.', $nombres)).($i + 1).'@correo.test',
                    'city' => $origen,
                    'origin' => $origen,
                    'destination' => $destino,
                    'travel_purpose' => $motivo,
                ],
            );
        }

        $this->command->line('   · Huéspedes: '.count($huespedes).' registrados (4 extranjeros)');

        return $huespedes;
    }

    /* ------------------------------------------------------------------ */
    /*  Historia: lo que le da contenido a los reportes */
    /* ------------------------------------------------------------------ */

    /**
     * Estadías cerradas de los últimos 60 días.
     *
     * **Se cierran sin factura a propósito.** Facturarlas quemaría 45 números
     * de la resolución y, como la factura se emite con la fecha del día en que
     * se cobra, las 45 saldrían fechadas hoy: el reporte de ventas mostraría
     * dos meses de ingresos concentrados en una sola jornada. Lo que estos
     * datos alimentan es el reporte de ocupación, ADR y RevPAR, que lee los
     * renglones de noche del folio —la única fuente que sabe de qué noche era
     * cada peso— y no necesita facturas.
     *
     * La factura se muestra en vivo haciendo un check-out de verdad sobre una
     * de las habitaciones ocupadas.
     */
    protected function creaLaHistoria(): void
    {
        $yaHay = Stay::query()
            ->where('hotel_id', $this->hotel->id)
            ->where('status', Stay::STATUS_CLOSED)
            ->count();

        if ($yaHay >= 30) {
            $this->command->line("   · Historia: {$yaHay} estadías cerradas ya existen, no la regenero");

            return;
        }

        $habitaciones = Room::query()
            ->where('hotel_id', $this->hotel->id)
            // Las que van a quedar ocupadas al final se dejan en paz.
            ->whereNotIn('number', self::HABITACIONES_DE_LA_DEMO)
            ->with('roomType')
            ->get();

        $huespedes = Guest::query()->where('company_id', $this->company->id)->get();
        $folio = app(HotelFolio::class);
        $creadas = 0;

        // Se recorre el calendario y cada día se ocupa un puñado de
        // habitaciones. Así la ocupación sube y baja como en un hotel de
        // verdad —los fines de semana más— en vez de salir plana.
        for ($diasAtras = 60; $diasAtras >= 2; $diasAtras--) {
            $fecha = now()->copy()->subDays($diasAtras)->startOfDay();
            $esFinDeSemana = $fecha->isFriday() || $fecha->isSaturday();

            // Cuántas entradas ese día. Las cifras están calibradas para que
            // la ocupación del período quede alrededor del 55-65%, que es la
            // de un hotel que va bien. Con menos, el reporte de la demo le
            // dice al cliente que el hotel está vacío; con más, que está
            // lleno todos los días y nadie se lo cree.
            $cuantas = $esFinDeSemana ? random_int(7, 10) : random_int(4, 7);

            for ($i = 0; $i < $cuantas; $i++) {
                $noches = random_int(1, 4);
                $salida = $fecha->copy()->addDays($noches);

                // No se pasa de ayer: una estadía cerrada en el futuro no
                // tiene sentido y además chocaría con las que están abiertas.
                if ($salida->gte(now()->startOfDay())) {
                    continue;
                }

                // Se busca una habitación libre probando varias, no una sola.
                // Escogiendo una al azar y rindiéndose si chocaba, la mitad de
                // los intentos se perdían y la ocupación del reporte quedaba
                // en la mitad de lo buscado: un hotel que parece vacío.
                $habitacion = null;

                foreach ($habitaciones->shuffle()->take(8) as $candidata) {
                    $choca = Stay::query()
                        ->where('room_id', $candidata->id)
                        ->whereDate('check_in_at', '<', $salida->toDateString())
                        ->whereDate('expected_check_out', '>', $fecha->toDateString())
                        ->exists();

                    if (! $choca) {
                        $habitacion = $candidata;
                        break;
                    }
                }

                if (! $habitacion) {
                    continue;
                }

                $huesped = $huespedes->random();
                $tarifa = (float) $habitacion->roomType->base_rate;

                // Nace abierta y se cierra al final, no al revés: cargar un
                // consumo a un folio cerrado está prohibido —y con razón—, así
                // que crearla cerrada dejaría toda la historia sin minibar y
                // la columna de consumos del reporte en cero.
                $estadia = Stay::create([
                    'company_id' => $this->company->id,
                    'hotel_id' => $this->hotel->id,
                    'room_id' => $habitacion->id,
                    'room_type_id' => $habitacion->room_type_id,
                    'primary_guest_id' => $huesped->id,
                    'prefix' => 'H',
                    'number' => $this->siguienteNumeroDeFolio(),
                    'check_in_at' => $fecha->copy()->setTime(random_int(14, 21), random_int(0, 59)),
                    'expected_check_out' => $salida->toDateString(),
                    'nights' => $noches,
                    'adults' => min((int) $habitacion->roomType->max_adults, random_int(1, 2)),
                    'children' => 0,
                    'rate_per_night' => $tarifa,
                    'status' => Stay::STATUS_OPEN,
                    'created_by_user_id' => $this->recepcion->id,
                    'notes' => 'Estadía histórica de demostración. Se cerró sin factura para no consumir '
                        .'consecutivos de la resolución; alimenta el reporte de ocupación, ADR y RevPAR.',
                ]);

                $estadia->guests()->attach($huesped->id, [
                    'company_id' => $this->company->id,
                    'is_primary' => true,
                ]);

                // Las noches, una por una: es lo que leen los reportes.
                for ($n = 0; $n < $noches; $n++) {
                    $folio->agregarNoche($estadia, $fecha->copy()->addDays($n), $tarifa);
                }

                // Algo del minibar en la mitad de las estadías.
                if (random_int(1, 100) <= 55) {
                    $this->cargaConsumosDemo($estadia->fresh(), $fecha, $noches);
                }

                $estadia = $folio->recalcular($estadia->fresh());

                $estadia->forceFill([
                    'status' => Stay::STATUS_CLOSED,
                    'check_out_at' => $salida->copy()->setTime(random_int(7, 11), random_int(0, 59)),
                    'closed_at' => $salida,
                    'closed_by_user_id' => $this->recepcion->id,
                    'paid_total' => (float) $estadia->total,
                ])->save();

                $creadas++;
            }
        }

        $this->command->line("   · Historia: {$creadas} estadías cerradas en los últimos 60 días");
    }

    /** Carga uno o dos consumos a una estadía, con fecha dentro del rango. */
    protected function cargaConsumosDemo(Stay $estadia, Carbon $entrada, int $noches): void
    {
        $folio = app(HotelFolio::class);
        $codigos = array_keys($this->productos);

        $cuantos = random_int(1, 3);

        for ($i = 0; $i < $cuantos; $i++) {
            $producto = $this->productos[$codigos[array_rand($codigos)]];

            try {
                $folio->agregarConsumo($estadia, [
                    'product_id' => $producto->id,
                    'description' => $producto->name,
                    'quantity' => random_int(1, 2),
                    'unit_price' => (float) $producto->default_sale_price,
                    'tax_id' => $producto->default_sale_tax_id,
                    'charge_date' => $entrada->copy()->addDays(random_int(0, max(0, $noches - 1)))->toDateString(),
                ]);
            } catch (\Throwable) {
                // Un consumo que no entra no vale la pena romper la demo.
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Lo que está pasando ahora */
    /* ------------------------------------------------------------------ */

    /**
     * Seis habitaciones ocupadas, cada una contando una situación distinta.
     *
     * Es lo que hace que la recepción se vea viva al abrirla, y lo que permite
     * mostrar el folio, el traslado, la cortesía y el check-out sin tener que
     * montar nada en el momento.
     */
    protected function creaLasEstadiasAbiertas(): void
    {
        $abiertas = Stay::query()
            ->where('hotel_id', $this->hotel->id)
            ->abiertas()
            ->count();

        if ($abiertas >= 4) {
            $this->command->line("   · Estadías abiertas: {$abiertas} ya existen, no las regenero");

            return;
        }

        $motor = app(HotelStayEngine::class);
        $folio = app(HotelFolio::class);
        $huespedes = Guest::query()->where('company_id', $this->company->id)->get()->keyBy('document_number');

        // [número, días que lleva dentro, días hasta la salida, huésped, exenta, qué muestra]
        $casos = [
            ['103', 2, 2, '1020304050', false, 'folio con minibar'],
            ['201', 1, 3, '79456123', false, 'estadía corporativa'],
            ['206', 3, 1, '71234567', false, 'familiar con acompañante'],
            ['301', 0, 2, '52789456', false, 'llegó hoy'],
            ['401', 4, -1, '80123456', false, 'se pasó de la fecha de salida'],
            ['402', 1, 4, 'AB1234567', true, 'extranjero exento de IVA'],
        ];

        $creadas = 0;

        foreach ($casos as [$numero, $llevaDentro, $hastaSalida, $documento, $exenta, $muestra]) {
            $habitacion = Room::query()
                ->where('hotel_id', $this->hotel->id)
                ->where('number', $numero)
                ->with('roomType')
                ->first();

            if (! $habitacion || $habitacion->estaOcupada()) {
                continue;
            }

            $huesped = $huespedes->get($documento);
            $entrada = now()->copy()->subDays($llevaDentro)->setTime(15, 20);
            $salida = now()->copy()->addDays($hastaSalida)->startOfDay();

            // El que se pasó de la fecha: su salida pactada ya pasó.
            if ($hastaSalida < 0) {
                $salida = now()->copy()->subDay()->startOfDay();
            }

            try {
                $estadia = $motor->checkIn([
                    'hotel_id' => $this->hotel->id,
                    'room_id' => $habitacion->id,
                    'check_in_at' => $entrada->toDateTimeString(),
                    'expected_check_out' => $salida->toDateString(),
                    'adults' => min(2, (int) $habitacion->roomType->max_adults),
                    'children' => $numero === '206' ? 1 : 0,
                    'primary_guest_id' => $huesped?->id,
                    'guest_ids' => $huesped ? [$huesped->id] : [],
                    'tax_exempt' => $exenta,
                    'tax_exempt_reason' => $exenta
                        ? 'Turista extranjero no residente — pasaporte '.$documento.' (art. 481 ET)'
                        : null,
                    'notes' => 'Demo: '.$muestra,
                ]);
            } catch (\Throwable $e) {
                $this->command->warn("   · No se pudo alojar en la {$numero}: {$e->getMessage()}");

                continue;
            }

            // La 206 va con acompañante: la tarjeta de registro los pide todos.
            if ($numero === '206') {
                $menor = $huespedes->get('1145678932');

                if ($menor && $huesped) {
                    $motor->registrarHuespedes($estadia, [$huesped->id, $menor->id], (int) $huesped->id);
                }
            }

            // Consumos en las que llevan días dentro.
            if ($llevaDentro >= 1) {
                $this->cargaConsumosDemo($estadia->fresh(), $entrada->copy()->startOfDay(), max(1, $llevaDentro));
            }

            // Una cortesía en la 401, que es la que se pasó de la fecha: deja
            // ver cómo se ve una rebaja en el folio y en la factura.
            if ($numero === '401') {
                try {
                    $folio->agregarAjuste(
                        $estadia->fresh(),
                        'Cortesía: el aire acondicionado falló la primera noche',
                        60000,
                    );
                } catch (\Throwable) {
                }
            }

            $creadas++;
        }

        $this->command->line("   · Estadías abiertas: {$creadas} habitaciones ocupadas ahora");
    }

    /* ------------------------------------------------------------------ */
    /*  Reservas */
    /* ------------------------------------------------------------------ */

    protected function creaLasReservas(): void
    {
        $yaHay = Reservation::query()->where('hotel_id', $this->hotel->id)->count();

        if ($yaHay >= 8) {
            $this->command->line("   · Reservas: {$yaHay} ya existen, no las regenero");

            return;
        }

        $motor = app(HotelReservationEngine::class);

        // [nombre, días desde hoy hasta la entrada, noches, tipo, origen,
        //  habitación fija, estado final]
        $casos = [
            ['Marcela Ocampo Díaz', 0, 2, 'DOB', 'phone', '202', 'confirmed'],
            ['Grupo Andino S.A.S.', 0, 3, 'TWIN', 'agency', '203', 'confirmed'],
            ['Felipe Arango Toro', 0, 1, 'SEN', 'whatsapp', null, 'pending'],
            ['Laura Jiménez Mesa', 1, 2, 'SUITE', 'booking', '403', 'confirmed'],
            ['Ricardo Peña Soto', 3, 4, 'FAM', 'direct', '303', 'confirmed'],
            ['Valentina Cruz Lara', 5, 2, 'DOB', 'airbnb', null, 'pending'],
            ['Congreso de Odontología', 9, 3, 'TWIN', 'agency', '204', 'confirmed'],
            ['Sebastián Núñez Gil', 12, 1, 'DOB', 'phone', null, 'pending'],
            ['Diana Castaño Ruiz', 2, 2, 'DOB', 'direct', null, 'cancelled'],
            ['Hernán Villa Muñoz', -2, 1, 'SEN', 'walk_in', null, 'no_show'],
        ];

        $creadas = 0;

        foreach ($casos as [$nombre, $enDias, $noches, $tipo, $origen, $habitacion, $estado]) {
            $entrada = now()->copy()->addDays($enDias)->startOfDay();
            $salida = $entrada->copy()->addDays($noches);

            $roomId = null;

            if ($habitacion) {
                $roomId = Room::query()
                    ->where('hotel_id', $this->hotel->id)
                    ->where('number', $habitacion)
                    ->value('id');
            }

            try {
                $reserva = $motor->crear([
                    'hotel_id' => $this->hotel->id,
                    'room_id' => $roomId,
                    'room_type_id' => $this->tipos[$tipo]->id,
                    'check_in_date' => $entrada->toDateString(),
                    'check_out_date' => $salida->toDateString(),
                    'guest_name' => $nombre,
                    'guest_phone' => '3'.random_int(100000000, 199999999),
                    'guest_email' => strtolower(str_replace(' ', '.', explode(' ', $nombre)[0])).'@correo.test',
                    // Nunca por encima de la capacidad del tipo: una sencilla
                    // admite una persona y el motor —con razón— rechaza dos.
                    'adults' => min(
                        $tipo === 'FAM' ? 3 : 2,
                        (int) $this->tipos[$tipo]->max_adults,
                    ),
                    'children' => $tipo === 'FAM' ? 1 : 0,
                    'source' => $origen,
                    'deposit_amount' => in_array($origen, ['booking', 'airbnb', 'agency'], true)
                        ? (float) $this->tipos[$tipo]->base_rate
                        : 0,
                    'notes' => $origen === 'agency' ? 'Facturar a la agencia.' : null,
                ]);
            } catch (\Throwable $e) {
                $this->command->warn("   · Reserva de {$nombre} no entró: {$e->getMessage()}");

                continue;
            }

            try {
                match ($estado) {
                    'confirmed' => $motor->confirmar($reserva),
                    'cancelled' => $motor->cancelar($reserva, 'El cliente cambió de fechas.'),
                    'no_show' => $motor->noLlego($reserva),
                    default => null,
                };
            } catch (\Throwable) {
                // Un cambio de estado que no aplica no tumba la demo.
            }

            $creadas++;
        }

        $llegadasHoy = Reservation::query()
            ->where('hotel_id', $this->hotel->id)
            ->llegadasDe(now())
            ->count();

        $this->command->line("   · Reservas: {$creadas} creadas · {$llegadasHoy} llegadas para hoy");
    }

    /* ------------------------------------------------------------------ */
    /*  Remates */
    /* ------------------------------------------------------------------ */

    /**
     * Dos habitaciones en aseo y una en mantenimiento.
     *
     * No es decoración: deja ver los seis estados del mapa y, sobre todo, que
     * una habitación en mantenimiento sale del inventario vendible también
     * para fechas futuras.
     */
    protected function ponHabitacionesEnAseo(): void
    {
        $aseo = ['105', '305'];
        $mantenimiento = '404';

        foreach ($aseo as $numero) {
            Room::query()
                ->where('hotel_id', $this->hotel->id)
                ->where('number', $numero)
                ->where('status', Room::STATUS_AVAILABLE)
                ->update(['status' => Room::STATUS_CLEANING]);
        }

        Room::query()
            ->where('hotel_id', $this->hotel->id)
            ->where('number', $mantenimiento)
            ->where('status', Room::STATUS_AVAILABLE)
            ->update([
                'status' => Room::STATUS_MAINTENANCE,
                'notes' => 'Cambio de aire acondicionado. Vuelve el próximo lunes.',
            ]);
    }

    /**
     * Turno de caja abierto para el recepcionista.
     *
     * Sin turno, el primer check-out de la demostración falla con «no tienes un
     * turno de caja abierto», que es correcto pero es lo último que uno quiere
     * explicar con el cliente mirando.
     */
    protected function abreElTurnoDeCaja(): void
    {
        $abierto = CashRegisterSession::query()
            ->where('company_id', $this->company->id)
            ->where('cashier_user_id', $this->recepcion->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->exists();

        if ($abierto) {
            return;
        }

        CashRegisterSession::create([
            'company_id' => $this->company->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->recepcion->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now()->startOfDay()->addHours(6),
            'opening_amount' => 200000,
            'opening_notes' => 'Base del turno de la mañana (demo).',
        ]);

        $this->command->line('   · Turno de caja abierto para recepción');
    }

    /* ------------------------------------------------------------------ */

    protected function siguienteNumeroDeFolio(): int
    {
        return (int) Stay::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('prefix', 'H')
            ->max('number') + 1;
    }

    protected function resumen(): void
    {
        $habitaciones = Room::query()->where('hotel_id', $this->hotel->id)->count();
        $ocupadas = Room::query()->where('hotel_id', $this->hotel->id)
            ->where('status', Room::STATUS_OCCUPIED)->count();
        $estadias = Stay::query()->where('hotel_id', $this->hotel->id)->count();
        $noches = StayCharge::query()
            ->whereIn('stay_id', Stay::query()->where('hotel_id', $this->hotel->id)->select('id'))
            ->noches()
            ->count();
        $reservas = Reservation::query()->where('hotel_id', $this->hotel->id)->count();
        $huespedes = Guest::query()->where('company_id', $this->company->id)->count();

        $this->command->newLine();
        $this->command->info('✅ Demo de Hoteles lista.');
        $this->command->line("   Empresa:    {$this->company->name} (NIT ".self::NIT.')');
        $this->command->line("   Admin:      {$this->admin->email}");
        $this->command->line("   Recepción:  {$this->recepcion->email}");
        $this->command->line('   Password:   '.$this->password());
        $this->command->line('   Entra en:   /app/hotel');
        $this->command->newLine();
        $this->command->line("   Habitaciones: {$habitaciones} ({$ocupadas} ocupadas ahora)");
        $this->command->line("   Estadías:     {$estadias} · {$noches} noches vendidas");
        $this->command->line("   Reservas:     {$reservas}");
        $this->command->line("   Huéspedes:    {$huespedes}");
        $this->command->newLine();
        // En producción, una contraseña que está escrita en el repositorio es
        // una puerta abierta a una empresa real de la base de producción.
        if (app()->isProduction() && $this->password() === 'Demo2026!') {
            $this->command->newLine();
            $this->command->warn(
                '   ⚠  Esta cuenta quedó en PRODUCCIÓN con la contraseña por defecto, que está '
                .'escrita en el repositorio.'
            );
            $this->command->warn(
                '      Cámbiala desde el panel, o vuelve a correr el seeder con '
                .'HOTEL_DEMO_PASSWORD=… para fijar otra.'
            );
        }

        $this->command->newLine();
        $this->command->comment('   Para la demostración, en este orden:');
        $this->command->line('     1. Recepción: el mapa, las llegadas de hoy, los KPIs de ocupación');
        $this->command->line('     2. Clic en la 103 → el folio con noches y minibar');
        $this->command->line('     3. Clic en la 402 → extranjero exento de IVA (art. 481 ET)');
        $this->command->line('     4. Clic en la 401 → se pasó de la fecha, y tiene una cortesía aplicada');
        $this->command->line('     5. Check-out de la 103 → factura POS de verdad, con su tiquete');
        $this->command->line('     6. Reportes → Ocupación e ingresos: ADR y RevPAR de 60 días');
        $this->command->line('     7. Entra como recepción: no tiene «cerrar sin facturar» ni cortesías');
    }
}
