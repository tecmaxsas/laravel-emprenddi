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
