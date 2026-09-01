<?php

namespace App\Services\Products;

use App\Models\Account;
use App\Models\Category;
use App\Models\InventoryOpening;
use App\Models\Location;
use App\Models\Product;
use App\Models\Tax;
use App\Services\Inventory\InventoryOpeningEngine;
use App\Services\Inventory\InventoryOpeningNumberer;
use App\Support\SpreadsheetCell;
use App\Services\Inventory\InventoryEngine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Motor de importacion masiva de productos desde XLSX.
 *
 * Uso tipico (2 fases):
 *
 *   $engine = app(ProductImportEngine::class);
 *   $preview = $engine->parseAndValidate($tmpPath, $companyId);
 *   // -> ['rows' => [...], 'summary' => [...], 'valid' => bool]
 *   // El controller muestra $preview al usuario; si confirma:
 *   $result = $engine->import($preview['rows'], $companyId);
 *   // -> ['created' => N, 'updated' => M, 'errors' => [...]]
 *
 * Reglas:
 *   - Match por (company_id, code) — existentes UPDATE, nuevos CREATE
 *   - 2 pasadas: primero padres/simples, luego variantes (necesitan parent_product_id)
 *   - Cache de lookups (categoria, impuesto, cuenta) por code para no
 *     hacer 1 query por celda
 *   - Todo dentro de una transaccion; si algo falla, rollback completo
 */
class ProductImportEngine
{
    /** @var array<string,int|null> */
    protected array $categoryCache = [];
    /** @var array<string,int|null> */
    protected array $taxCache = [];
    /** @var array<string,int|null> */
    protected array $accountCache = [];
    /** @var array<string,int|null> */
    protected array $locationCache = [];

    public function __construct(
        protected InventoryOpeningEngine $openingEngine,
        protected InventoryOpeningNumberer $openingNumberer,
    ) {}

    /**
     * Parsea el archivo y valida cada fila. NO persiste nada.
     *
     * @return array{rows: array, summary: array, valid: bool}
     */
    /** Filas de la hoja de stock que se omitieron por no traer cantidad. */
    protected int $stockOmitidas = 0;

    /** Filas cuyo costo se tomo del precio de compra del producto. */
    protected int $stockCostoDelProducto = 0;

    public function parseAndValidate(string $filePath, int $companyId): array
    {
        $this->resetCaches();

        $reader = new Reader();
        $reader->open($filePath);

        $rows = [];
        $stockRows = [];
        $productsSheetFound = false;

        foreach ($reader->getSheetIterator() as $sheet) {
            $name = trim($sheet->getName());

            if (preg_match('/^productos/i', $name)) {
                $productsSheetFound = true;
                $rows = $this->readProductsSheet($sheet, $companyId);
                continue;
            }

            if (preg_match('/^inventario\s*inicial/i', $name)) {
                $stockRows = $this->readInitialStockSheet($sheet, $companyId, $rows);
                continue;
            }
        }
        $reader->close();

        if (! $productsSheetFound) {
            return [
                'rows' => [],
                'stock_rows' => [],
                'summary' => ['total' => 0, 'ok' => 0, 'errors' => 0, 'to_create' => 0, 'to_update' => 0, 'stock_lines' => 0, 'stock_locations' => 0, 'stock_errors' => 0],
                'valid' => false,
                'fatal' => 'No se encontró la hoja "Productos" en el archivo. Usa la plantilla oficial.',
            ];
        }

        // Segunda validacion: los variation_of_code deben apuntar a un
        // padre existente en DB o presente en el archivo. Se puede
        // referenciar por CODE o por NAME (util cuando el padre no tiene
        // code y sera auto-generado).
        $codesInFile = collect($rows)->pluck('data.code')->filter()->values()->all();
        $variableNamesInFile = collect($rows)
            ->filter(fn ($r) => ($r['data']['type'] ?? '') === 'variable')
            ->pluck('data.name')->filter()
            ->map(fn ($n) => strtolower(trim((string) $n)))
            ->values()->all();

        foreach ($rows as &$r) {
            $vof = $r['data']['variation_of_code'] ?? null;
            if (! $vof) continue;
            if (in_array($vof, $codesInFile, true)) continue;
            if (in_array(strtolower(trim((string) $vof)), $variableNamesInFile, true)) continue;
            $existsByCode = Product::query()
                ->where('company_id', $companyId)
                ->where('code', $vof)
                ->where('type', 'variable')
                ->exists();
            $existsByName = Product::query()
                ->where('company_id', $companyId)
                ->where('name', $vof)
                ->where('type', 'variable')
                ->exists();
            if (! $existsByCode && ! $existsByName) {
                $r['errors'][] = "El padre '{$vof}' no existe ni viene en el archivo. Debe ser un producto tipo=variable ya creado o incluido en esta importación (referéncialo por code o por name).";
            }
        }
        unset($r);

        // Detecta duplicados de code dentro del archivo (solo entre codes NO vacios)
        $dupCodes = collect($rows)->pluck('data.code')
            ->filter()->countBy()->filter(fn ($n) => $n > 1)->keys()->all();
        if (! empty($dupCodes)) {
            foreach ($rows as &$r) {
                if (in_array($r['data']['code'] ?? null, $dupCodes, true)) {
                    $r['errors'][] = "Código duplicado dentro del archivo: '{$r['data']['code']}'.";
                }
            }
            unset($r);
        }

        $summary = $this->summarize($rows, $companyId);

        // Agregar metrics de stock inicial al summary
        $stockErrors = 0;
        $stockLocations = [];
        foreach ($stockRows as $sr) {
            if (! empty($sr['errors'])) $stockErrors++;
            if (! empty($sr['data']['location_code'])) {
                $stockLocations[strtolower($sr['data']['location_code'])] = true;
            }
        }
        $summary['stock_lines'] = count($stockRows);
        $summary['stock_skipped'] = $this->stockOmitidas;
        $summary['stock_cost_from_product'] = $this->stockCostoDelProducto;
        $summary['stock_locations'] = count($stockLocations);
        $summary['stock_errors'] = $stockErrors;

        $valid = $summary['errors'] === 0 && $stockErrors === 0 && $summary['total'] > 0;

        return [
            'rows' => $rows,
            'stock_rows' => $stockRows,
            'summary' => $summary,
            'valid' => $valid,
        ];
    }

    /**
     * Lee la hoja "Productos" y devuelve el array validado.
     */
    protected function readProductsSheet($sheet, int $companyId): array
    {
        $rows = [];
        $rowNum = 0;
        $headers = null;

        foreach ($sheet->getRowIterator() as $row) {
            $rowNum++;
            // SpreadsheetCell y no getValue(): en una celda con formula
            // getValue() devuelve el texto de la formula, que como numero da
            // 0 — de ahi salieron los precios de venta en cero.
            $cells = array_map(
                fn ($c) => SpreadsheetCell::value($c),
                $row->getCells(),
            );

            if ($rowNum === 1) {
                $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $cells);
                continue;
            }

            $allEmpty = ! array_filter($cells, fn ($v) => $v !== null && $v !== '');
            if ($allEmpty) continue;

            $data = $this->rowToAssoc($headers, $cells);
            $rows[] = $this->validateRow($data, $rowNum, $companyId);
        }
        return $rows;
    }

    /**
     * Lee la hoja "Inventario Inicial" y devuelve las lineas validadas.
     * Valida que el product_code exista (en la hoja o en DB) y la
     * location_code sea real en la empresa.
     */
    protected function readInitialStockSheet($sheet, int $companyId, array $productRows): array
    {
        // Referencias validas dentro del archivo: por CODE o por NAME
        $codesInFile = collect($productRows)->pluck('data.code')->filter()
            ->map(fn ($c) => strtolower(trim((string) $c)))->all();
        $namesInFile = collect($productRows)->pluck('data.name')->filter()
            ->map(fn ($n) => strtolower(trim((string) $n)))->all();

        $lines = [];
        $rowNum = 0;
        $headers = null;
        $omitidas = 0;
        $desdeElProducto = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $rowNum++;
            // SpreadsheetCell y no getValue(): en una celda con formula
            // getValue() devuelve el texto de la formula, que como numero da
            // 0 — de ahi salieron los precios de venta en cero.
            $cells = array_map(
                fn ($c) => SpreadsheetCell::value($c),
                $row->getCells(),
            );

            if ($rowNum === 1) {
                $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $cells);
                continue;
            }

            $allEmpty = ! array_filter($cells, fn ($v) => $v !== null && $v !== '');
            if ($allEmpty) continue;

            $data = [];
            foreach (ProductImportTemplateGenerator::STOCK_COLUMNS as $col) {
                $idx = array_search($col, $headers, true);
                $data[$col] = $idx !== false ? ($cells[$idx] ?? null) : null;
            }
            // Conserva el valor original — puede ser code o name (el
            // lookup en createInitialStockOpenings acepta ambos).
            $data['product_code'] = trim((string) ($data['product_code'] ?? ''));
            $data['location_code'] = trim((string) ($data['location_code'] ?? ''));

            $vacio = fn (mixed $v) => $v === null || trim((string) $v) === '';

            // Fila que solo trae el codigo del producto: es un producto de la
            // lista que NO tiene stock inicial. Se omite en silencio en vez de
            // reportarlo como error — es la forma natural de llenar la hoja,
            // pegando todos los codigos y poniendo cantidad solo a los que
            // tienen existencias.
            if ($data['product_code'] !== ''
                && $data['location_code'] === ''
                && $vacio($data['qty'])
                && $vacio($data['unit_cost'])) {
                $omitidas++;
                continue;
            }

            // Sin cantidad tampoco hay nada que cargar. Vale 0 o vacio: las
            // dos formas de decir "este no tiene".
            if ($vacio($data['qty']) || (is_numeric($data['qty']) && (float) $data['qty'] == 0.0)) {
                $omitidas++;
                continue;
            }

            // El costo en blanco toma el precio de compra del producto.
            //
            // El motor contable exige costo > 0, y con razon: una apertura a
            // costo cero deja el inventario sin valor y hace que cada venta
            // posterior calcule un costo de ventas de 0, inflando la utilidad.
            // Pero pedir otro archivo cuando el precio de compra ya esta
            // cargado es hacerle dar vueltas al usuario.
            if ($vacio($data['unit_cost']) || (float) $data['unit_cost'] == 0.0) {
                $costoProducto = $this->precioDeCompraDe($data['product_code'], $companyId);

                if ($costoProducto > 0) {
                    $data['unit_cost'] = $costoProducto;
                    $data['unit_cost_from_product'] = true;
                    $desdeElProducto++;
                } else {
                    $data['unit_cost'] = 0;
                }
            }

            $errors = [];
            if (! $data['product_code']) $errors[] = 'product_code es obligatorio (puede ser el code o el name del producto)';
            if (! $data['location_code']) $errors[] = 'location_code es obligatorio';
            if (! is_numeric($data['qty']) || (float) $data['qty'] <= 0) {
                $errors[] = 'qty debe ser un número > 0';
            }
            if (! is_numeric($data['unit_cost']) || (float) $data['unit_cost'] <= 0) {
                $errors[] = 'unit_cost vacío y el producto tampoco tiene precio de compra. '
                    .'El inventario tiene que entrar valorizado: si no, cada venta de este producto '
                    .'calcularía un costo de $0.';
            }

            // Validar referencia: primero en el archivo (code o name),
            // luego en DB (code o name).
            if ($data['product_code']) {
                $refLower = strtolower($data['product_code']);
                $inFile = in_array($refLower, $codesInFile, true)
                       || in_array($refLower, $namesInFile, true);
                if (! $inFile) {
                    $existsInDb = Product::query()
                        ->where('company_id', $companyId)
                        ->where(function ($q) use ($data) {
                            $q->where('code', $data['product_code'])
                              ->orWhere('name', $data['product_code']);
                        })
                        ->exists();
                    if (! $existsInDb) {
                        $errors[] = "product_code '{$data['product_code']}' no existe (ni en el archivo ni en la base — puedes usar el code o el name)";
                    }
                }
            }

            if ($data['location_code']
                && $this->resolveLocationId($data['location_code'], $companyId) === null) {
                $errors[] = "location_code '{$data['location_code']}' no existe";
            }

            // La apertura de inventario SUMA: no reemplaza. Si el producto ya
            // tiene existencias en esa sede, volver a importarlas las duplica
            // y ademas deja un asiento contable de mas. Se marca aqui para
            // avisarlo antes de escribir nada.
            $yaTieneExistencias = false;
            if ($errors === []) {
                $yaTieneExistencias = $this->stockActualDe(
                    $data['product_code'],
                    $data['location_code'],
                    $companyId,
                ) > 0;
            }

            $lines[] = [
                'row_number' => $rowNum,
                'data' => $data,
                'errors' => $errors,
                'already_has_stock' => $yaTieneExistencias,
            ];
        }

        $this->stockOmitidas = $omitidas;
        $this->stockCostoDelProducto = $desdeElProducto;

        return $lines;
    }

    /**
     * Importa las filas validadas. Debe llamarse solo si parseAndValidate
     * retorno valid=true. Dos pasadas: primero padres/simples, luego variantes.
     *
     * Si se pasan stockRows y counterpartAccountId, tras crear los productos
     * se generan y postean InventoryOpening (una por sede) con las lineas
     * correspondientes.
     *
     * @param array $rows           Filas validadas de la hoja Productos
     * @param int $companyId
     * @param array $stockRows      Filas validadas de la hoja Inventario Inicial
     * @param int|null $counterpartAccountId  Cuenta contrapartida CR (ej. 3705)
     *
     * @return array{created:int, updated:int, errors:array, openings:array}
     */
    public function import(
        array $rows,
        int $companyId,
        array $stockRows = [],
        ?int $counterpartAccountId = null,
        bool $allowStockOnTop = false,
    ): array {
        // Importar el inventario inicial dos veces duplica las existencias y
        // deja un asiento de mas. Es dificil de deshacer, asi que se para
        // antes de escribir salvo que lo pidan a proposito.
        if (! $allowStockOnTop) {
            $conStock = array_values(array_filter(
                $stockRows,
                fn ($r) => empty($r['errors']) && ($r['already_has_stock'] ?? false),
            ));

            if ($conStock !== []) {
                $ejemplos = array_map(
                    fn ($r) => $r['data']['product_code'].' en '.$r['data']['location_code'],
                    array_slice($conStock, 0, 5),
                );

                throw new \RuntimeException(sprintf(
                    "%d productos de la hoja \"Inventario Inicial\" YA tienen existencias. "
                    ."La apertura suma, no reemplaza: importarla otra vez duplicaría el inventario.\n\n%s%s\n\n"
                    .'Quita esas filas del archivo, o marca "sumar sobre las existencias actuales" '
                    .'si de verdad quieres agregarlas.',
                    count($conStock),
                    implode("\n", $ejemplos),
                    count($conStock) > 5 ? "\n… y ".(count($conStock) - 5).' más.' : '',
                ));
            }
        }

        $created = 0;
        $updated = 0;
        $errors = [];
        $openingsCreated = [];

        // Filtra filas validas
        $valid = array_values(array_filter($rows, fn ($r) => empty($r['errors'])));

        // Pre-genera codes para filas con code vacio. Se hace ANTES de
        // dividir en pases porque las variantes pueden referenciar a un
        // padre que se acaba de codigar.
        $valid = $this->assignAutoCodes($valid, $companyId);

        // Pasada 1: padres/simples (no variantes)
        $pass1 = array_filter($valid, fn ($r) => empty($r['data']['variation_of_code']));
        // Pasada 2: variantes
        $pass2 = array_filter($valid, fn ($r) => ! empty($r['data']['variation_of_code']));

        DB::transaction(function () use (
            &$created, &$updated, &$errors, &$openingsCreated,
            $pass1, $pass2, $companyId, $stockRows, $counterpartAccountId,
        ) {
            foreach ([$pass1, $pass2] as $batch) {
                foreach ($batch as $r) {
                    try {
                        $wasUpdate = $this->createOrUpdateProduct($r['data'], $companyId);
                        $wasUpdate ? $updated++ : $created++;
                    } catch (\Throwable $e) {
                        $errors[] = [
                            'row_number' => $r['row_number'],
                            'code' => $r['data']['code'] ?? '',
                            'message' => $e->getMessage(),
                        ];
                    }
                }
            }

            // Stock inicial — solo si hay filas y cuenta contrapartida
            $validStock = array_values(array_filter($stockRows, fn ($r) => empty($r['errors'])));
            if (! empty($validStock) && $counterpartAccountId) {
                $openingsCreated = $this->createInitialStockOpenings(
                    $validStock,
                    $companyId,
                    $counterpartAccountId,
                    $errors,
                );
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'openings' => $openingsCreated,
        ];
    }

    /**
     * Agrupa las lineas de stock por location y crea/postea una
     * InventoryOpening por sede. Retorna lista de openings creadas.
     * Errores se acumulan en el array pasado por referencia.
     */
    protected function createInitialStockOpenings(
        array $validStockLines,
        int $companyId,
        int $counterpartAccountId,
        array &$errors,
    ): array {
        // Agrupa por location_code (normalizado)
        $byLocation = [];
        foreach ($validStockLines as $sl) {
            $key = strtoupper(trim($sl['data']['location_code']));
            $byLocation[$key][] = $sl;
        }

        $openings = [];
        $companyModel = \App\Models\Company::find($companyId);

        foreach ($byLocation as $locCode => $lines) {
            $locationId = $this->resolveLocationId($locCode, $companyId);
            if (! $locationId) {
                $errors[] = [
                    'row_number' => $lines[0]['row_number'] ?? 0,
                    'code' => "STOCK/{$locCode}",
                    'message' => "Sede '{$locCode}' no encontrada al crear apertura de inventario.",
                ];
                continue;
            }

            try {
                // Crear cabecera
                $number = $this->openingNumberer->next($companyModel, 'SI');
                $opening = InventoryOpening::create([
                    'company_id' => $companyId,
                    'location_id' => $locationId,
                    'counterpart_account_id' => $counterpartAccountId,
                    'prefix' => 'SI',
                    'number' => $number,
                    'date' => now()->toDateString(),
                    'status' => InventoryOpening::STATUS_DRAFT,
                    'notes' => 'Apertura automática desde importación masiva de productos',
                    'created_by_user_id' => Auth::id(),
                ]);

                // Crear lineas
                $lineNum = 1;
                foreach ($lines as $sl) {
                    // Match por code primero, luego por name (para productos
                    // con code auto-generado que el usuario referencia por
                    // nombre en la hoja Stock).
                    $ref = $sl['data']['product_code'];
                    $product = Product::query()
                        ->where('company_id', $companyId)
                        ->where(function ($q) use ($ref) {
                            $q->where('code', $ref)->orWhere('name', $ref);
                        })
                        ->first();
                    if (! $product) {
                        $errors[] = [
                            'row_number' => $sl['row_number'],
                            'code' => 'STOCK/'.$sl['data']['product_code'],
                            'message' => "Producto no encontrado despues del import.",
                        ];
                        continue;
                    }
                    if (! $product->track_inventory) {
                        $errors[] = [
                            'row_number' => $sl['row_number'],
                            'code' => 'STOCK/'.$sl['data']['product_code'],
                            'message' => "Producto no controla inventario (track_inventory=false).",
                        ];
                        continue;
                    }
                    $opening->lines()->create([
                        'line_number' => $lineNum++,
                        'product_id' => $product->id,
                        'quantity' => (float) $sl['data']['qty'],
                        'unit_cost' => (float) $sl['data']['unit_cost'],
                    ]);
                }

                // Postear la apertura (contabiliza + crea movimientos)
                $this->openingEngine->post($opening->fresh(['lines']));

                $openings[] = [
                    'location_code' => $locCode,
                    'number' => "SI-{$number}",
                    'lines' => count($lines),
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'row_number' => $lines[0]['row_number'] ?? 0,
                    'code' => "STOCK/{$locCode}",
                    'message' => "Error al postear apertura: {$e->getMessage()}",
                ];
            }
        }
        return $openings;
    }

    /**
     * Existencias actuales del producto en esa sede. 0 si no se puede
     * resolver: en ese caso ya hay un error de validacion que lo explica.
     */
    /** Precio de compra del producto, para valorizar la apertura. */
    protected function precioDeCompraDe(string $productRef, int $companyId): float
    {
        return (float) Product::query()
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('code', $productRef)->orWhere('name', $productRef))
            ->value('default_purchase_price');
    }

    protected function stockActualDe(string $productRef, string $locationCode, int $companyId): float
    {
        $locationId = $this->resolveLocationId($locationCode, $companyId);

        if (! $locationId) {
            return 0.0;
        }

        $productId = Product::query()
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('code', $productRef)->orWhere('name', $productRef))
            ->value('id');

        if (! $productId) {
            return 0.0;
        }

        return app(InventoryEngine::class)->currentStock((int) $productId, $locationId);
    }

    protected function resolveLocationId(?string $code, int $companyId): ?int
    {
        if (! $code) return null;
        $key = strtolower($code);
        if (array_key_exists($key, $this->locationCache)) return $this->locationCache[$key];
        $id = Location::query()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->where(function ($q) use ($code) {
                $q->where('code', $code)->orWhere('name', $code);
            })
            ->value('id');
        return $this->locationCache[$key] = $id ? (int) $id : null;
    }

    /* ------------------------------------------------------------------ */

    protected function createOrUpdateProduct(array $data, int $companyId): bool
    {
        $existing = Product::query()
            ->where('company_id', $companyId)
            ->where('code', $data['code'])
            ->first();

        $payload = $this->buildPayload($data, $companyId);

        // Si es variante, agrega parent_product_id. El match es por code
        // primero, luego por name (para soportar padres sin codigo que
        // fueron auto-generados o cuando el usuario referencia por nombre).
        if (! empty($data['variation_of_code'])) {
            $vof = $data['variation_of_code'];
            $parent = Product::query()
                ->where('company_id', $companyId)
                ->where('type', 'variable')
                ->where(function ($q) use ($vof) {
                    $q->where('code', $vof)->orWhere('name', $vof);
                })
                ->first();
            if (! $parent) {
                throw new \RuntimeException("Padre '{$vof}' no existe (busqué por code y por name).");
            }
            $payload['parent_product_id'] = $parent->id;
        }

        if ($existing) {
            $existing->update($payload);
            return true; // update
        }
        Product::create(array_merge(['company_id' => $companyId], $payload));
        return false; // create
    }

    /**
     * Asigna codes generados a filas con code vacio. Formato: PROD-NNNN
     * secuencial por empresa. Considera codes existentes en DB para no
     * colisionar. Tambien resuelve `variation_of_code` en variantes que
     * apuntan al padre por NAME cuando el padre no tiene code: cambia el
     * variation_of_code al nuevo code generado del padre para que
     * createOrUpdateProduct resuelva por code (mas rapido).
     */
    protected function assignAutoCodes(array $valid, int $companyId): array
    {
        // Filas sin code (por generar)
        $needCode = array_filter($valid, fn ($r) => empty(trim((string) ($r['data']['code'] ?? ''))));
        if (empty($needCode)) return $valid;

        // Encuentra el siguiente numero disponible (driver-agnostic: extrae
        // el numero en PHP en vez de usar SUBSTRING nativo, que difiere
        // entre postgres/mysql).
        $lastNum = 0;
        Product::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', 'like', 'PROD-%')
            ->pluck('code')
            ->each(function ($code) use (&$lastNum) {
                if (preg_match('/^PROD-(\d+)$/', $code, $m)) {
                    $n = (int) $m[1];
                    if ($n > $lastNum) $lastNum = $n;
                }
            });

        // Mapa: temp_key (nombre normalizado del padre) => code generado
        $parentNameToCode = [];

        foreach ($valid as $i => &$r) {
            if (empty(trim((string) ($r['data']['code'] ?? '')))) {
                $lastNum++;
                $newCode = 'PROD-'.str_pad((string) $lastNum, 4, '0', STR_PAD_LEFT);
                $r['data']['code'] = $newCode;
                $r['data']['_auto_generated_code'] = true;

                // Si es padre variable, guarda mapping name -> new code
                if (($r['data']['type'] ?? '') === 'variable' && ! empty($r['data']['name'])) {
                    $parentNameToCode[strtolower(trim($r['data']['name']))] = $newCode;
                }
            }
        }
        unset($r);

        // Segunda pasada: reemplaza variation_of_code que apuntan por nombre
        // al code recien generado del padre. Asi createOrUpdateProduct
        // resuelve por code directo.
        foreach ($valid as &$r) {
            $vof = $r['data']['variation_of_code'] ?? null;
            if (! $vof) continue;
            $key = strtolower(trim((string) $vof));
            if (isset($parentNameToCode[$key])) {
                $r['data']['variation_of_code'] = $parentNameToCode[$key];
            }
        }
        unset($r);

        return $valid;
    }

    protected function buildPayload(array $data, int $companyId): array
    {
        // Defaults por tipo
        $type = $data['type'];
        $defaults = match ($type) {
            'service' => ['track_inventory' => false, 'is_purchasable' => false, 'is_sellable' => true],
            'variable' => ['track_inventory' => false, 'is_purchasable' => false, 'is_sellable' => false],
            'consumable' => ['track_inventory' => true, 'is_purchasable' => true, 'is_sellable' => false],
            'kit' => ['track_inventory' => false, 'is_purchasable' => false, 'is_sellable' => true],
            default => ['track_inventory' => true, 'is_purchasable' => true, 'is_sellable' => true],
        };

        $payload = [
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $type,
            'description' => $data['description'] ?: null,
            'barcode' => $data['barcode'] ?: null,
            'brand' => $data['brand'] ?: null,
            'unit_of_measure' => $data['unit'] ?: 'unit',
            'category_id' => $this->resolveCategoryId($data['category_code'], $companyId),
            'is_sellable' => $this->coerceBool($data['is_sellable'], $defaults['is_sellable']),
            'is_purchasable' => $this->coerceBool($data['is_purchasable'], $defaults['is_purchasable']),
            'track_inventory' => $this->coerceBool($data['track_inventory'], $defaults['track_inventory']),
            'tracks_serials' => $this->coerceBool($data['tracks_serials'], false),
            'warranty_days' => (int) ($data['warranty_days'] ?: 0),
            'default_sale_price' => (float) ($data['sale_price'] ?: 0),
            'sale_price_includes_tax' => $this->coerceBool($data['sale_price_includes_tax'], false),
            'default_sale_tax_id' => $this->resolveTaxId($data['sale_tax_code'], $companyId),
            'default_purchase_price' => (float) ($data['purchase_price'] ?: 0),
            'default_purchase_tax_id' => $this->resolveTaxId($data['purchase_tax_code'], $companyId),
            'sale_account_id' => $this->resolveAccountId($data['sale_account_code'], $companyId),
            'inventory_account_id' => $this->resolveAccountId($data['inventory_account_code'], $companyId),
            'cost_account_id' => $this->resolveAccountId($data['cost_account_code'], $companyId),
            'active' => $this->coerceBool($data['active'], true),
        ];

        return $payload;
    }

    /* ------------------------------------------------------------------ */

    protected function validateRow(array $data, int $rowNumber, int $companyId): array
    {
        $errors = [];

        // code es OPCIONAL — si viene vacio, el engine lo genera al importar.
        if (! ($data['name'] ?? null)) $errors[] = 'name es obligatorio';
        $type = strtolower(trim((string) ($data['type'] ?? '')));
        if (! $type) $errors[] = 'type es obligatorio';
        elseif (! in_array($type, ['good', 'service', 'kit', 'consumable', 'variable'], true)) {
            $errors[] = "type '{$type}' invalido (debe ser: good, service, kit, consumable, variable)";
        }
        $data['type'] = $type;

        // Numeros: si se pasa algo que no es numerico, error
        foreach (['sale_price', 'purchase_price', 'warranty_days'] as $numCol) {
            $v = $data[$numCol] ?? null;
            if ($v !== null && $v !== '' && ! is_numeric($v)) {
                $errors[] = "{$numCol} debe ser numérico (recibido: '{$v}')";
            }
        }

        // Referencias que resuelven a ID: si el usuario paso algo pero no
        // matchea, error explicito.
        if (! empty($data['category_code']) && $this->resolveCategoryId($data['category_code'], $companyId) === null) {
            $errors[] = "category_code '{$data['category_code']}' no existe";
        }
        foreach (['sale_tax_code', 'purchase_tax_code'] as $taxCol) {
            if (! empty($data[$taxCol]) && $this->resolveTaxId($data[$taxCol], $companyId) === null) {
                $errors[] = "{$taxCol} '{$data[$taxCol]}' no existe";
            }
        }
        foreach (['sale_account_code', 'purchase_account_code', 'inventory_account_code', 'cost_account_code'] as $acctCol) {
            if (! empty($data[$acctCol]) && $this->resolveAccountId($data[$acctCol], $companyId) === null) {
                $errors[] = "{$acctCol} '{$data[$acctCol]}' no existe o no acepta movimientos";
            }
        }

        // Regla de variantes: solo un producto simple (good/service) puede
        // ser variante — no anidamos variables.
        if (! empty($data['variation_of_code']) && $type === 'variable') {
            $errors[] = 'Una variante no puede ser type=variable (solo el padre).';
        }

        return [
            'row_number' => $rowNumber,
            'data' => $data,
            'errors' => $errors,
        ];
    }

    protected function summarize(array $rows, int $companyId): array
    {
        $total = count($rows);
        $errors = 0;
        $toCreate = 0;
        $toUpdate = 0;

        $existingCodes = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('code', collect($rows)->pluck('data.code')->filter()->all())
            ->pluck('code')->all();

        foreach ($rows as $r) {
            if (! empty($r['errors'])) {
                $errors++;
                continue;
            }
            in_array($r['data']['code'] ?? null, $existingCodes, true) ? $toUpdate++ : $toCreate++;
        }
        return [
            'total' => $total,
            'ok' => $total - $errors,
            'errors' => $errors,
            'to_create' => $toCreate,
            'to_update' => $toUpdate,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    protected function resetCaches(): void
    {
        $this->stockOmitidas = 0;
        $this->stockCostoDelProducto = 0;

        $this->categoryCache = [];
        $this->taxCache = [];
        $this->accountCache = [];
        $this->locationCache = [];
    }

    protected function rowToAssoc(array $headers, array $cells): array
    {
        $out = [];
        foreach (ProductImportTemplateGenerator::COLUMNS as $col) {
            $idx = array_search($col, $headers, true);
            $out[$col] = $idx !== false ? ($cells[$idx] ?? null) : null;
        }
        // Normaliza codes a upper trim
        foreach (['code', 'variation_of_code', 'category_code', 'sale_tax_code', 'purchase_tax_code',
                  'sale_account_code', 'purchase_account_code', 'inventory_account_code', 'cost_account_code'] as $c) {
            if (isset($out[$c])) {
                $out[$c] = trim((string) $out[$c]);
            }
        }
        return $out;
    }

    protected function coerceBool(mixed $v, bool $default): bool
    {
        if ($v === null || $v === '') return $default;
        if (is_bool($v)) return $v;
        if (is_numeric($v)) return (bool) (int) $v;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['si', 'sí', 'yes', 'true', '1', 'x'], true);
    }

    protected function resolveCategoryId(?string $code, int $companyId): ?int
    {
        if (! $code) return null;
        $key = strtolower($code);
        if (array_key_exists($key, $this->categoryCache)) return $this->categoryCache[$key];
        $id = Category::query()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($code) {
                $q->where('code', $code)->orWhere('name', $code);
            })
            ->value('id');
        return $this->categoryCache[$key] = $id ? (int) $id : null;
    }

    protected function resolveTaxId(?string $code, int $companyId): ?int
    {
        if (! $code) return null;
        $key = strtolower($code);
        if (array_key_exists($key, $this->taxCache)) return $this->taxCache[$key];
        $id = Tax::query()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($code) {
                $q->where('code', $code)->orWhere('name', $code);
            })
            ->value('id');
        return $this->taxCache[$key] = $id ? (int) $id : null;
    }

    protected function resolveAccountId(?string $code, int $companyId): ?int
    {
        if (! $code) return null;
        $key = strtolower($code);
        if (array_key_exists($key, $this->accountCache)) return $this->accountCache[$key];
        $id = Account::query()
            ->where('company_id', $companyId)
            ->where('accepts_movements', true)
            ->where('active', true)
            ->where('code', $code)
            ->value('id');
        return $this->accountCache[$key] = $id ? (int) $id : null;
    }
}
