<?php

namespace App\Services\Demo;

use App\Models\Category;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tax;
use App\Models\ThirdParty;
use App\Models\User;
use App\Services\Onboarding\CompanyOnboarding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Crea empresas demo completas con catalogo, clientes, proveedores y —
 * en caso restaurante — modificadores, zonas y mesas. Idempotente: si
 * la empresa ya existe se actualiza/completa sin duplicar.
 *
 * Se usa desde:
 *   - php artisan demo:seed retail|restaurant|both
 *   - SuperAdmin → boton 'Cargar empresa demo'
 */
class DemoSeeder
{
    public function __construct(private CompanyOnboarding $onboarding)
    {
    }

    public function seedRetail(): array
    {
        return DB::transaction(function () {
            $company = $this->createCompany([
                'nit' => '900111111',
                'dv' => '1',
                'name' => 'Demo Retail SAS',
                'legal_name' => 'Demo Retail Sociedad por Acciones Simplificada',
                'email' => 'demo-retail@emprenddi.com',
                'address' => 'Carrera 7 #45-12',
                'modules' => ['electronic_billing'],
            ]);

            $admin = $this->createAdmin($company, 'demo-retail@emprenddi.com', 'Admin Retail');
            $this->onboarding->bootstrap($company->fresh());

            $categories = $this->seedRetailCategories($company);
            $productCount = $this->seedRetailProducts($company, $categories);
            $clients = $this->seedClients($company, $this->retailClients());
            $suppliers = $this->seedSuppliers($company, $this->retailSuppliers());

            Log::info('[DemoSeeder] retail seeded', [
                'company_id' => $company->id, 'products' => $productCount,
                'clients' => count($clients), 'suppliers' => count($suppliers),
            ]);

            return [
                'company' => $company,
                'admin_email' => $admin->email,
                'admin_password' => 'Demo2026!',
                'login_url' => url('/app/login'),
                'categories' => count($categories),
                'products' => $productCount,
                'clients' => count($clients),
                'suppliers' => count($suppliers),
            ];
        });
    }

    public function seedSport(): array
    {
        return DB::transaction(function () {
            $company = $this->createCompany([
                'nit' => '900333333',
                'dv' => '3',
                'name' => 'Sportime Store SAS',
                'legal_name' => 'Sportime Store Sociedad por Acciones Simplificada',
                'email' => 'demo-sport@emprenddi.com',
                'address' => 'Carrera 13 #93-12',
                'modules' => ['electronic_billing'],
            ]);

            $admin = $this->createAdmin($company, 'demo-sport@emprenddi.com', 'Admin Sportime');

            // Pre-crear sedes ANTES del bootstrap para que el provisioner
            // de "Sede Principal" se salte (es idempotente: si ya hay
            // locations no crea otra). Bodega Central queda como is_main.
            $locations = $this->seedSportLocations($company);

            $this->onboarding->bootstrap($company->fresh());

            $categories = $this->seedSportCategories($company);
            $productCount = $this->seedSportProducts($company, $categories);
            $this->ensureProductLocations($company, $locations);

            $clients = $this->seedClients($company, $this->sportClients());
            $suppliers = $this->seedSuppliers($company, $this->sportSuppliers());

            $historyCount = $this->populateSportHistory($company, $admin, $locations, $clients);

            Log::info('[DemoSeeder] sport seeded', [
                'company_id' => $company->id, 'products' => $productCount,
                'clients' => count($clients), 'suppliers' => count($suppliers),
                'locations' => count($locations), 'history' => $historyCount,
            ]);

            return [
                'company' => $company,
                'admin_email' => $admin->email,
                'admin_password' => 'Demo2026!',
                'login_url' => url('/app/login'),
                'categories' => count($categories),
                'products' => $productCount,
                'clients' => count($clients),
                'suppliers' => count($suppliers),
                'locations' => count($locations),
                'history' => $historyCount,
            ];
        });
    }

    public function seedRestaurant(): array
    {
        return DB::transaction(function () {
            $company = $this->createCompany([
                'nit' => '900222222',
                'dv' => '2',
                'name' => 'Demo Restaurante SAS',
                'legal_name' => 'Demo Restaurante Sociedad por Acciones Simplificada',
                'email' => 'demo-restaurante@emprenddi.com',
                'address' => 'Calle 85 #11-20',
                'modules' => ['electronic_billing', 'restaurant'],
            ]);

            $admin = $this->createAdmin($company, 'demo-restaurante@emprenddi.com', 'Admin Restaurante');
            $this->onboarding->bootstrap($company->fresh());

            $categories = $this->seedRestaurantCategories($company);
            $modifierGroups = $this->seedRestaurantModifiers($company);
            $productCount = $this->seedRestaurantProducts($company, $categories, $modifierGroups);
            $clients = $this->seedClients($company, $this->restaurantClients());
            $suppliers = $this->seedSuppliers($company, $this->restaurantSuppliers());
            $tableCount = $this->seedRestaurantZonesAndTables($company);

            Log::info('[DemoSeeder] restaurant seeded', [
                'company_id' => $company->id, 'products' => $productCount,
                'tables' => $tableCount, 'modifier_groups' => count($modifierGroups),
            ]);

            return [
                'company' => $company,
                'admin_email' => $admin->email,
                'admin_password' => 'Demo2026!',
                'login_url' => url('/app/login'),
                'categories' => count($categories),
                'products' => $productCount,
                'clients' => count($clients),
                'suppliers' => count($suppliers),
                'modifier_groups' => count($modifierGroups),
                'tables' => $tableCount,
            ];
        });
    }

    // ============================================================
    // EMPRESA + ADMIN
    // ============================================================

    private function createCompany(array $data): Company
    {
        return Company::withoutGlobalScopes()->firstOrCreate(
            ['nit' => $data['nit']],
            [
                'name' => $data['name'],
                'legal_name' => $data['legal_name'],
                'document_type' => 'nit',
                'organization_type' => 'juridica',
                'dv' => $data['dv'],
                'regime_type' => 'comun',
                'accounting_method' => 'niif_pymes',
                'inventory_method' => 'weighted_average',
                'address' => $data['address'],
                'city' => 'Bogotá',
                'department' => 'Cundinamarca',
                'phone' => '6014567890',
                'phone_country_code' => '+57',
                'email' => $data['email'],
                'currency' => 'COP',
                'timezone' => 'America/Bogota',
                'active_modules' => $data['modules'],
                'active' => true,
            ],
        );
    }

    private function createAdmin(Company $company, string $email, string $name): User
    {
        $user = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => $email],
            [
                'company_id' => $company->id,
                'name' => $name,
                'last_name' => 'Demo',
                'password' => Hash::make('Demo2026!'),
                'is_super_admin' => false,
                'active' => true,
                'email_verified_at' => now(),
                'accepted_terms_at' => now(),
            ],
        );

        if (! $user->company_id) {
            $user->update(['company_id' => $company->id]);
        }

        if (! $user->hasRole('admin')) {
            $user->assignRole('admin');
        }

        return $user;
    }

    // ============================================================
    // CATEGORIAS — RETAIL
    // ============================================================

    private function seedRetailCategories(Company $company): array
    {
        $defs = [
            ['Mercado', 'MERC', '🛒'],
            ['Bebidas', 'BEB', '🥤'],
            ['Snacks y Dulces', 'SNK', '🍫'],
            ['Aseo y Hogar', 'ASE', '🧹'],
            ['Cuidado Personal', 'CP', '🧴'],
            ['Electrónicos', 'ELEC', '🔌'],
        ];

        $created = [];
        foreach ($defs as $i => [$name, $code, $icon]) {
            $created[$code] = Category::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $name, 'icon' => $icon, 'sort_order' => $i + 1, 'active' => true],
            );
        }
        return $created;
    }

    private function seedRetailProducts(Company $company, array $cats): int
    {
        $iva19 = $this->findTax($company, 19);
        $iva5 = $this->findTax($company, 5);

        $items = [
            // Mercado (IVA 5%/0% tipico)
            ['MERC', 'Arroz 1 kg', 'ARZ001', '7702000000011', 3500, 4500, $iva5],
            ['MERC', 'Aceite Vegetal 1 L', 'ACT001', '7702000000028', 8000, 11000, $iva5],
            ['MERC', 'Azúcar Blanca 1 kg', 'AZU001', '7702000000035', 4000, 5500, $iva5],
            ['MERC', 'Sal Refinada 1 kg', 'SAL001', '7702000000042', 2500, 3500, $iva5],
            ['MERC', 'Pasta Espagueti 500 g', 'PAS001', '7702000000059', 3000, 4200, $iva5],
            ['MERC', 'Frijol Rojo 500 g', 'FRJ001', '7702000000066', 5500, 7500, $iva5],
            ['MERC', 'Lenteja 500 g', 'LEN001', '7702000000073', 4500, 6500, $iva5],
            ['MERC', 'Café Tostado 250 g', 'CAF001', '7702000000080', 12000, 18000, $iva19],

            // Bebidas
            ['BEB', 'Gaseosa Cola 1.5 L', 'GAS001', '7702010000017', 3500, 5500, $iva19],
            ['BEB', 'Agua Sin Gas 600 ml', 'AGU001', '7702010000024', 1500, 2500, $iva19],
            ['BEB', 'Jugo Mango Tetrapack 1 L', 'JUG001', '7702010000031', 4000, 6000, $iva19],
            ['BEB', 'Cerveza Nacional 330 ml', 'CER001', '7702010000048', 2800, 4500, $iva19],
            ['BEB', 'Energizante 250 ml', 'ENG001', '7702010000055', 3500, 5500, $iva19],

            // Snacks
            ['SNK', 'Papas Fritas 150 g', 'PAP001', '7702020000016', 4500, 7000, $iva19],
            ['SNK', 'Chocolate Barra 50 g', 'CHO001', '7702020000023', 2500, 4000, $iva19],
            ['SNK', 'Galletas Dulces 200 g', 'GLT001', '7702020000030', 3000, 4800, $iva19],
            ['SNK', 'Maní Salado 100 g', 'MAN001', '7702020000047', 3500, 5500, $iva19],
            ['SNK', 'Chicles Pack', 'CHC001', '7702020000054', 1500, 2500, $iva19],

            // Aseo
            ['ASE', 'Detergente en Polvo 1 kg', 'DET001', '7702030000015', 8000, 12500, $iva19],
            ['ASE', 'Jabón Loza 250 g', 'JBL001', '7702030000022', 4500, 7000, $iva19],
            ['ASE', 'Limpiador Multiusos 1 L', 'LMP001', '7702030000039', 6500, 10000, $iva19],
            ['ASE', 'Papel Higiénico x 12', 'PAP002', '7702030000046', 12000, 18500, $iva19],
            ['ASE', 'Bolsas de Basura x 30', 'BLS001', '7702030000053', 5500, 8500, $iva19],

            // Cuidado Personal
            ['CP', 'Shampoo 400 ml', 'SHM001', '7702040000014', 12000, 18500, $iva19],
            ['CP', 'Jabón de Tocador 90 g', 'JBT001', '7702040000021', 2500, 4000, $iva19],
            ['CP', 'Crema Dental 100 ml', 'CRD001', '7702040000038', 5500, 8500, $iva19],
            ['CP', 'Desodorante Roll-On', 'DES001', '7702040000045', 9500, 14500, $iva19],
            ['CP', 'Cepillo de Dientes', 'CDD001', '7702040000052', 4500, 7000, $iva19],

            // Electrónicos
            ['ELEC', 'Pilas AA x 4', 'PIL001', '7702050000013', 7500, 12000, $iva19],
            ['ELEC', 'Audífonos Cableados', 'AUD001', '7702050000020', 18000, 28000, $iva19],
            ['ELEC', 'Cable USB-C 1 m', 'USB001', '7702050000037', 12000, 19500, $iva19],
            ['ELEC', 'Cargador USB 5V', 'CRG001', '7702050000044', 22000, 35000, $iva19],
            ['ELEC', 'Bombillo LED 9W', 'BLB001', '7702050000051', 9500, 15000, $iva19],
        ];

        $count = 0;
        foreach ($items as [$catCode, $name, $code, $barcode, $cost, $price, $tax]) {
            Product::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'barcode' => $barcode,
                    'type' => 'good',
                    'category_id' => $cats[$catCode]->id,
                    'default_purchase_price' => $cost,
                    'default_sale_price' => $price,
                    'default_sale_tax_id' => $tax?->id,
                    'default_purchase_tax_id' => $tax?->id,
                    'unit_of_measure' => 'und',
                    'is_purchasable' => true,
                    'is_sellable' => true,
                    'track_inventory' => true,
                    'active' => true,
                ],
            );
            $count++;
        }
        return $count;
    }

    // ============================================================
    // CATEGORIAS — RESTAURANTE
    // ============================================================

    private function seedRestaurantCategories(Company $company): array
    {
        $defs = [
            ['Entradas', 'ENT', '🥗'],
            ['Platos Fuertes', 'PRI', '🍽️'],
            ['Hamburguesas', 'HMB', '🍔'],
            ['Pizzas', 'PIZ', '🍕'],
            ['Bebidas', 'BEB', '🥤'],
            ['Postres', 'PST', '🍰'],
            ['Adicionales', 'ADI', '➕'],
        ];

        $created = [];
        foreach ($defs as $i => [$name, $code, $icon]) {
            $created[$code] = Category::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $name, 'icon' => $icon, 'sort_order' => $i + 1, 'active' => true],
            );
        }
        return $created;
    }

    private function seedRestaurantProducts(Company $company, array $cats, array $modifierGroups): int
    {
        // Restaurante usa impuesto al consumo 8% (no IVA). Si no existe en
        // el catalogo, cae a IVA 19% como fallback.
        $iva8 = $this->findTax($company, 8, 'consumption_tax')
            ?? $this->findTax($company, 19, 'vat');

        // [catCode, name, code, sale_price, modifier_group_codes[]]
        $items = [
            // Entradas
            ['ENT', 'Ceviche de Pescado', 'ENT001', 18000, []],
            ['ENT', 'Empanadas (3 und)', 'ENT002', 9000, []],
            ['ENT', 'Patacones con Hogao', 'ENT003', 12000, []],
            ['ENT', 'Sopa del Día', 'ENT004', 10000, []],

            // Platos Fuertes
            ['PRI', 'Bandeja Paisa', 'PRI001', 32000, []],
            ['PRI', 'Pollo a la Plancha', 'PRI002', 26000, ['PUNTO_POLLO']],
            ['PRI', 'Lomo de Cerdo BBQ', 'PRI003', 30000, ['PUNTO_CARNE']],
            ['PRI', 'Trucha al Ajillo', 'PRI004', 35000, []],
            ['PRI', 'Pasta Carbonara', 'PRI005', 24000, []],
            ['PRI', 'Pasta Bolognesa', 'PRI006', 22000, []],

            // Hamburguesas
            ['HMB', 'Hamburguesa Clásica', 'HMB001', 18000, ['PUNTO_CARNE', 'EXTRAS_BURGER']],
            ['HMB', 'Hamburguesa BBQ', 'HMB002', 22000, ['PUNTO_CARNE', 'EXTRAS_BURGER']],
            ['HMB', 'Hamburguesa Doble Carne', 'HMB003', 26000, ['PUNTO_CARNE', 'EXTRAS_BURGER']],

            // Pizzas
            ['PIZ', 'Pizza Margarita', 'PIZ001', 32000, ['TAMAÑO_PIZZA']],
            ['PIZ', 'Pizza Hawaiana', 'PIZ002', 36000, ['TAMAÑO_PIZZA']],
            ['PIZ', 'Pizza Pepperoni', 'PIZ003', 38000, ['TAMAÑO_PIZZA']],

            // Bebidas
            ['BEB', 'Limonada Natural', 'BEB001', 6000, []],
            ['BEB', 'Jugo Natural en Agua', 'BEB002', 7000, []],
            ['BEB', 'Gaseosa Personal', 'BEB003', 4500, []],
            ['BEB', 'Cerveza Nacional', 'BEB004', 8000, []],
            ['BEB', 'Cerveza Importada', 'BEB005', 12000, []],
            ['BEB', 'Agua sin Gas', 'BEB006', 4000, []],
            ['BEB', 'Café Americano', 'BEB007', 4500, []],

            // Postres
            ['PST', 'Brownie con Helado', 'PST001', 12000, []],
            ['PST', 'Cheesecake', 'PST002', 11000, []],
            ['PST', 'Flan de Caramelo', 'PST003', 9000, []],

            // Adicionales
            ['ADI', 'Porción de Papas', 'ADI001', 8000, []],
            ['ADI', 'Arroz Extra', 'ADI002', 5000, []],
            ['ADI', 'Ensalada de la Casa', 'ADI003', 7000, []],
        ];

        $count = 0;
        foreach ($items as [$catCode, $name, $code, $price, $groupCodes]) {
            $product = Product::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'type' => 'good',
                    'category_id' => $cats[$catCode]->id,
                    'default_sale_price' => $price,
                    'default_sale_tax_id' => $iva8?->id,
                    'unit_of_measure' => 'und',
                    'is_purchasable' => false,
                    'is_sellable' => true,
                    'track_inventory' => false,
                    'active' => true,
                ],
            );

            $groupIds = array_filter(array_map(
                fn($c) => $modifierGroups[$c]->id ?? null,
                $groupCodes,
            ));
            if (! empty($groupIds)) {
                $product->modifierGroups()->syncWithoutDetaching($groupIds);
            }
            $count++;
        }
        return $count;
    }

    // ============================================================
    // MODIFICADORES — RESTAURANTE
    // ============================================================

    private function seedRestaurantModifiers(Company $company): array
    {
        $groupModel = '\App\Models\Restaurant\ModifierGroup';
        $modModel = '\App\Models\Restaurant\Modifier';
        if (! class_exists($groupModel)) {
            $groupModel = '\App\Models\ModifierGroup';
            $modModel = '\App\Models\Modifier';
        }

        $groups = [
            'PUNTO_CARNE' => [
                'name' => 'Punto de cocción (carne)', 'required' => true, 'min' => 1, 'max' => 1,
                'options' => [['Poco hecho', 0], ['Término medio', 0], ['Tres cuartos', 0], ['Bien cocido', 0]],
            ],
            'PUNTO_POLLO' => [
                'name' => 'Acompañamiento pollo', 'required' => true, 'min' => 1, 'max' => 2,
                'options' => [['Arroz blanco', 0], ['Papas fritas', 2000], ['Ensalada', 1500], ['Puré de papa', 2500]],
            ],
            'EXTRAS_BURGER' => [
                'name' => 'Extras hamburguesa', 'required' => false, 'min' => 0, 'max' => 5,
                'options' => [['Queso extra', 3000], ['Tocineta', 4000], ['Aguacate', 3500], ['Cebolla caramelizada', 2500], ['Huevo frito', 3000]],
            ],
            'TAMAÑO_PIZZA' => [
                'name' => 'Tamaño', 'required' => true, 'min' => 1, 'max' => 1,
                'options' => [['Personal (25 cm)', 0], ['Mediana (30 cm)', 8000], ['Familiar (38 cm)', 15000]],
            ],
        ];

        $created = [];
        $order = 0;
        foreach ($groups as $code => $def) {
            $order++;
            $group = $groupModel::firstOrCreate(
                ['company_id' => $company->id, 'name' => $def['name']],
                [
                    'min_select' => $def['min'],
                    'max_select' => $def['max'],
                    'required' => $def['required'],
                    'display_order' => $order,
                    'active' => true,
                ],
            );

            foreach ($def['options'] as $i => [$optName, $priceDelta]) {
                $modModel::firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'restaurant_modifier_group_id' => $group->id,
                        'name' => $optName,
                    ],
                    [
                        'price_delta' => $priceDelta,
                        'display_order' => $i + 1,
                        'active' => true,
                    ],
                );
            }
            $created[$code] = $group;
        }
        return $created;
    }

    // ============================================================
    // ZONAS Y MESAS — RESTAURANTE
    // ============================================================

    private function seedRestaurantZonesAndTables(Company $company): int
    {
        $location = Location::query()
            ->where('company_id', $company->id)
            ->where('is_main', true)
            ->first()
            ?? Location::query()->where('company_id', $company->id)->orderBy('id')->first();

        if (! $location) {
            return 0;
        }

        $zoneModel = '\App\Models\Restaurant\ServiceZone';
        $tableModel = '\App\Models\Restaurant\Table';
        if (! class_exists($zoneModel)) {
            $zoneModel = '\App\Models\ServiceZone';
            $tableModel = '\App\Models\Table';
        }

        $zones = [
            ['SAL', 'Salón Principal', '#10b981', 8, 4, 'square'],
            ['TER', 'Terraza', '#3b82f6', 6, 4, 'round'],
            ['BAR', 'Barra', '#f59e0b', 4, 2, 'bar'],
        ];

        $tableCount = 0;
        foreach ($zones as $zi => [$zoneCode, $zoneName, $color, $count, $capacity, $shape]) {
            $zone = $zoneModel::firstOrCreate(
                ['company_id' => $company->id, 'location_id' => $location->id, 'code' => $zoneCode],
                ['name' => $zoneName, 'color' => $color, 'display_order' => $zi + 1, 'active' => true],
            );

            for ($i = 1; $i <= $count; $i++) {
                $tableCode = $zoneCode . '-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                $tableModel::firstOrCreate(
                    ['company_id' => $company->id, 'location_id' => $location->id, 'code' => $tableCode],
                    [
                        'zone_id' => $zone->id,
                        'capacity' => $capacity,
                        'shape' => $shape,
                        'status' => 'free',
                        'label' => 'Mesa ' . $i,
                        'active' => true,
                    ],
                );
                $tableCount++;
            }
        }
        return $tableCount;
    }

    // ============================================================
    // CLIENTES Y PROVEEDORES — comunes a ambos demos
    // ============================================================

    /** @return array<int,array{document_type:string,document_number:string,name:string,phone?:string,email?:string,city?:string}> */
    private function retailClients(): array
    {
        return [
            ['cc', '1020304050', 'María Fernanda López', '3001234567', 'maria.lopez@example.co', 'Bogotá'],
            ['cc', '1020304051', 'Carlos Andrés Pérez', '3007654321', 'carlos.perez@example.co', 'Bogotá'],
            ['cc', '1020304052', 'Ana Lucía Ramírez', '3009876543', 'ana.ramirez@example.co', 'Medellín'],
            ['cc', '1020304053', 'Jorge Iván Castro', '3001112233', 'jorge.castro@example.co', 'Cali'],
            ['nit', '901234567', 'Distribuidora El Sol SAS', '6014001020', 'compras@elsol.co', 'Bogotá'],
            ['nit', '901234568', 'Mercados La Plaza SAS', '6014001021', 'admin@laplaza.co', 'Bogotá'],
            ['cc', '1020304054', 'Sandra Milena Torres', '3001234568', 'sandra.torres@example.co', 'Barranquilla'],
            ['cc', '1020304055', 'Felipe Andrés Vargas', '3001234569', 'felipe.vargas@example.co', 'Bogotá'],
        ];
    }

    /** @return array<int,array{document_type:string,document_number:string,name:string,phone?:string,email?:string,city?:string}> */
    private function retailSuppliers(): array
    {
        return [
            ['nit', '900100100', 'Distribuidora Alimentos SAS', '6017001001', 'ventas@distalimentos.co', 'Bogotá'],
            ['nit', '900100101', 'Bebidas y Snacks Andinos SAS', '6017001002', 'comercial@bebandinos.co', 'Bogotá'],
            ['nit', '900100102', 'Aseo Total Colombia SAS', '6017001003', 'compras@aseototal.co', 'Bogotá'],
        ];
    }

    private function restaurantClients(): array
    {
        return [
            ['cc', '1030405060', 'Laura Camila Gómez', '3101112233', 'laura.gomez@example.co', 'Bogotá'],
            ['cc', '1030405061', 'Daniel Esteban Ruiz', '3104445566', 'daniel.ruiz@example.co', 'Bogotá'],
            ['cc', '1030405062', 'Valentina Ospina', '3107778899', 'valentina.ospina@example.co', 'Bogotá'],
            ['cc', '1030405063', 'Andrés Felipe Morales', '3101010101', 'andres.morales@example.co', 'Bogotá'],
            ['nit', '901300300', 'Eventos y Catering SAS', '6019001001', 'reservas@eventoscat.co', 'Bogotá'],
        ];
    }

    private function restaurantSuppliers(): array
    {
        return [
            ['nit', '900200200', 'Carnes Premium SAS', '6018001001', 'pedidos@carnespremium.co', 'Bogotá'],
            ['nit', '900200201', 'Frutas y Verduras del Campo SAS', '6018001002', 'ventas@frucampo.co', 'Bogotá'],
            ['nit', '900200202', 'Distribuidora de Licores Andina SAS', '6018001003', 'comercial@licoresandina.co', 'Bogotá'],
        ];
    }

    private function seedClients(Company $company, array $defs): array
    {
        $created = [];
        foreach ($defs as [$docType, $docNumber, $name, $phone, $email, $city]) {
            $created[] = ThirdParty::firstOrCreate(
                ['company_id' => $company->id, 'document_number' => $docNumber],
                [
                    'name' => $name,
                    'document_type' => $docType,
                    'person_type' => $docType === 'nit' ? 'juridica' : 'natural',
                    'is_customer' => true,
                    'is_supplier' => false,
                    'regime_type' => $docType === 'nit' ? 'comun' : 'no_responsable_iva',
                    'address' => 'Sin dirección registrada',
                    'city' => $city,
                    'phone' => $phone,
                    'email' => $email,
                    'active' => true,
                ],
            );
        }
        return $created;
    }

    private function seedSuppliers(Company $company, array $defs): array
    {
        $created = [];
        foreach ($defs as [$docType, $docNumber, $name, $phone, $email, $city]) {
            $created[] = ThirdParty::firstOrCreate(
                ['company_id' => $company->id, 'document_number' => $docNumber],
                [
                    'name' => $name,
                    'document_type' => $docType,
                    'person_type' => 'juridica',
                    'is_customer' => false,
                    'is_supplier' => true,
                    'regime_type' => 'comun',
                    'address' => 'Sin dirección registrada',
                    'city' => $city,
                    'phone' => $phone,
                    'email' => $email,
                    'active' => true,
                ],
            );
        }
        return $created;
    }

    // ============================================================
    // SEDES Y BODEGA — SPORT
    // ============================================================

    private function seedSportLocations(Company $company): array
    {
        $defs = [
            ['BOD', 'Bodega Central', 'warehouse', true,  'Calle 80 #75-50',         'Bogotá'],
            ['SCN', 'Sede Centro',    'store',     false, 'Carrera 13 #93-12',       'Bogotá'],
            ['SNT', 'Sede Norte',     'store',     false, 'Calle 140 #15-20',        'Bogotá'],
            ['SSR', 'Sede Sur',       'store',     false, 'Av. 1° de Mayo #45-10',   'Bogotá'],
        ];

        $created = [];
        foreach ($defs as [$code, $name, $type, $isMain, $address, $city]) {
            $created[$code] = Location::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'is_main' => $isMain,
                    'address' => $address,
                    'city' => $city,
                    'country' => 'CO',
                    'currency' => 'COP',
                    'timezone' => 'America/Bogota',
                    'active' => true,
                ],
            );
        }
        return $created;
    }

    // ============================================================
    // CATEGORIAS — SPORT
    // ============================================================

    private function seedSportCategories(Company $company): array
    {
        $defs = [
            ['Guayos y Botines', 'GYO', '⚽'],
            ['Ropa Deportiva',   'ROP', '👕'],
            ['Accesorios',       'ACC', '🎒'],
            ['Balones',          'BAL', '🏀'],
        ];

        $created = [];
        foreach ($defs as $i => [$name, $code, $icon]) {
            $created[$code] = Category::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $name, 'icon' => $icon, 'sort_order' => $i + 1, 'active' => true],
            );
        }
        return $created;
    }

    // ============================================================
    // PRODUCTOS — SPORT
    // ============================================================

    private function seedSportProducts(Company $company, array $cats): int
    {
        $iva19 = $this->findTax($company, 19);

        $items = [
            // Guayos y Botines
            ['GYO', 'Adidas Predator Pro FG',        'GYO001', '4549343001001', 250000, 389000],
            ['GYO', 'Nike Mercurial Vapor 15 Elite', 'GYO002', '4549343001002', 270000, 419000],
            ['GYO', 'Puma Future Match FG',          'GYO003', '4549343001003', 210000, 329000],
            ['GYO', 'Nike Phantom GX Academy',       'GYO004', '4549343001004', 240000, 389000],
            ['GYO', 'Adidas Copa Pure FG',           'GYO005', '4549343001005', 230000, 359000],
            ['GYO', 'Joma Aguila Top',               'GYO006', '4549343001006', 100000, 169000],
            ['GYO', 'Mizuno Morelia Neo III',        'GYO007', '4549343001007', 380000, 549000],

            // Ropa Deportiva
            ['ROP', 'Camiseta Selección Colombia Local', 'ROP001', '4549343002001', 180000, 299000],
            ['ROP', 'Camiseta Real Madrid Local 25/26',  'ROP002', '4549343002002', 180000, 299000],
            ['ROP', 'Camiseta Barcelona Local 25/26',    'ROP003', '4549343002003', 170000, 289000],
            ['ROP', 'Pantaloneta Adidas Tiro 23',        'ROP004', '4549343002004',  45000,  89000],
            ['ROP', 'Sudadera Nike Dri-FIT',             'ROP005', '4549343002005',  95000, 159000],
            ['ROP', 'Camisa Polo Deportiva',             'ROP006', '4549343002006',  40000,  79000],
            ['ROP', 'Medias de Fútbol Profesionales',    'ROP007', '4549343002007',  16000,  35000],
            ['ROP', 'Buzo Deportivo Adidas',             'ROP008', '4549343002008', 110000, 189000],

            // Accesorios
            ['ACC', 'Espinilleras Adidas X',         'ACC001', '4549343003001',  22000,  45000],
            ['ACC', 'Espinilleras Nike Mercurial',   'ACC002', '4549343003002',  28000,  55000],
            ['ACC', 'Mochila Nike Brasilia',         'ACC003', '4549343003003',  75000, 129000],
            ['ACC', 'Maletín Adidas Tiro',           'ACC004', '4549343003004',  70000, 119000],
            ['ACC', 'Termo Deportivo 750 ml',        'ACC005', '4549343003005',  18000,  39000],
            ['ACC', 'Tomatodo Sport 1 L',            'ACC006', '4549343003006',  14000,  29000],
            ['ACC', 'Guantes de Arquero',            'ACC007', '4549343003007',  45000,  89000],
            ['ACC', 'Vendas Elásticas (par)',        'ACC008', '4549343003008',   8000,  19000],
            ['ACC', 'Cinta Adhesiva Deportiva',      'ACC009', '4549343003009',   6000,  15000],
            ['ACC', 'Toalla de Microfibra',          'ACC010', '4549343003010',  10000,  25000],

            // Balones
            ['BAL', 'Balón Adidas FIFA Pro N°5',     'BAL001', '4549343004001', 120000, 199000],
            ['BAL', 'Balón Nike Strike N°5',         'BAL002', '4549343004002', 100000, 169000],
            ['BAL', 'Balón Penalty Storm N°4',       'BAL003', '4549343004003',  55000,  99000],
            ['BAL', 'Balón Microfútbol Voit',        'BAL004', '4549343004004',  45000,  79000],
            ['BAL', 'Balón Wilson Basketball',       'BAL005', '4549343004005',  75000, 129000],
        ];

        $count = 0;
        foreach ($items as [$catCode, $name, $code, $barcode, $cost, $price]) {
            Product::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'barcode' => $barcode,
                    'type' => 'good',
                    'category_id' => $cats[$catCode]->id,
                    'default_purchase_price' => $cost,
                    'default_sale_price' => $price,
                    'default_sale_tax_id' => $iva19?->id,
                    'default_purchase_tax_id' => $iva19?->id,
                    'unit_of_measure' => 'und',
                    'is_purchasable' => true,
                    'is_sellable' => true,
                    'track_inventory' => true,
                    'active' => true,
                ],
            );
            $count++;
        }
        return $count;
    }

    /**
     * Activa cada producto en cada location (product_locations). Necesario
     * para que la sede pueda venderlo y aparezca en el stock por sede.
     */
    private function ensureProductLocations(Company $company, array $locations): void
    {
        $productIds = Product::query()->where('company_id', $company->id)->pluck('id');
        foreach ($productIds as $pid) {
            foreach ($locations as $loc) {
                \App\Models\ProductLocation::firstOrCreate(
                    ['product_id' => $pid, 'location_id' => $loc->id],
                    ['active' => true],
                );
            }
        }
    }

    // ============================================================
    // CLIENTES Y PROVEEDORES — SPORT
    // ============================================================

    private function sportClients(): array
    {
        return [
            ['cc',  '1040506070', 'Camilo Rodríguez',              '3001110001', 'camilo.rodriguez@example.co', 'Bogotá'],
            ['cc',  '1040506071', 'Mariana Sánchez',               '3001110002', 'mariana.sanchez@example.co',  'Bogotá'],
            ['cc',  '1040506072', 'Andrés Felipe Bedoya',          '3001110003', 'andres.bedoya@example.co',    'Medellín'],
            ['cc',  '1040506073', 'Lina María Quintero',           '3001110004', 'lina.quintero@example.co',    'Bogotá'],
            ['cc',  '1040506074', 'Juan Camilo Mejía',             '3001110005', 'juan.mejia@example.co',       'Cali'],
            ['nit', '901400400',  'Academia Sub-15 Bogotá SAS',    '6014002001', 'compras@subxv.co',            'Bogotá'],
            ['nit', '901400401',  'Club Deportivo Los Pumas SAS',  '6014002002', 'admin@lospumas.co',           'Bogotá'],
        ];
    }

    private function sportSuppliers(): array
    {
        return [
            ['nit', '900400400', 'Distribuidora Deportes Andinos SAS', '6017002001', 'ventas@deportesandinos.co',    'Bogotá'],
            ['nit', '900400401', 'Importadora Sport Global SAS',       '6017002002', 'comercial@sportglobal.co',     'Bogotá'],
            ['nit', '900400402', 'Textiles Deportivos Colombia SAS',   '6017002003', 'pedidos@textilesdeportivos.co','Bogotá'],
        ];
    }

    // ============================================================
    // VENTAS HISTORICAS — SPORT (para que el dashboard luzca con datos)
    // ============================================================

    /**
     * Inserta facturas de venta de los últimos 14 días repartidas entre las
     * sedes (no en la bodega). Idempotente: si ya hay ventas posted, no
     * inserta. Va directo a la tabla — sin lineas/asiento — porque solo
     * alimenta el dashboard y el listado de Cartera. Para invoices reales
     * el demo crea ventas en vivo desde el POS.
     */
    private function populateSportHistory(Company $company, User $admin, array $locations, array $clients): int
    {
        $existing = \App\Models\SaleInvoice::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'posted')
            ->count();
        if ($existing > 0) {
            return 0;
        }

        $sedes = array_values(array_filter($locations, fn ($l) => $l->type === 'store'));
        if (empty($sedes) || empty($clients)) {
            return 0;
        }

        $count = 0;
        $number = 1;
        for ($daysAgo = 13; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo)->toDateString();
            $perDay = $daysAgo === 0 ? mt_rand(2, 4) : mt_rand(1, 3);

            for ($i = 0; $i < $perDay; $i++) {
                $loc = $sedes[array_rand($sedes)];
                $client = $clients[array_rand($clients)];

                $subtotal = mt_rand(50, 450) * 1000;
                $taxTotal = (int) round($subtotal * 0.19, 0);
                $total = $subtotal + $taxTotal;

                $r = mt_rand(1, 100);
                if ($r <= 60) {
                    $paymentStatus = 'pagado';
                    $paid = $total;
                } elseif ($r <= 85) {
                    $paymentStatus = 'parcial';
                    $paid = (int) round($total * 0.5, 0);
                } else {
                    $paymentStatus = 'pendiente';
                    $paid = 0;
                }

                \App\Models\SaleInvoice::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'location_id' => $loc->id,
                    'third_party_id' => $client->id,
                    'prefix' => 'FV',
                    'number' => $number++,
                    'date' => $date,
                    'currency' => 'COP',
                    'exchange_rate' => 1,
                    'subtotal' => $subtotal,
                    'discount_total' => 0,
                    'tax_total' => $taxTotal,
                    'total' => $total,
                    'paid_amount' => $paid,
                    'payment_status' => $paymentStatus,
                    'status' => 'posted',
                    'created_by_user_id' => $admin->id,
                    'posted_by_user_id' => $admin->id,
                    'seller_user_id' => $admin->id,
                    'posted_at' => $date,
                    'description' => 'Venta demo Sportime — '.$loc->name,
                ]);
                $count++;
            }
        }
        return $count;
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function findTax(Company $company, int $rate, string $type = 'vat'): ?Tax
    {
        return Tax::query()
            ->where('company_id', $company->id)
            ->where('type', $type)
            ->where('rate', $rate)
            ->where('applies_to', '!=', 'purchase')
            ->orderBy('id')
            ->first();
    }
}
