<?php

namespace App\Services\Products;

use App\Models\Account;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
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

    /**
     * Parsea el archivo y valida cada fila. NO persiste nada.
     *
     * @return array{rows: array, summary: array, valid: bool}
     */
    public function parseAndValidate(string $filePath, int $companyId): array
    {
        $this->resetCaches();

        $reader = new Reader();
        $reader->open($filePath);

        $rows = [];
        $rowNum = 0;
        $headers = null;
        $productsSheetFound = false;

        foreach ($reader->getSheetIterator() as $sheet) {
            // Busca la hoja "Productos" (nombre exacto o starts with)
            $name = trim($sheet->getName());
            if (! preg_match('/^productos/i', $name)) continue;
            $productsSheetFound = true;

            foreach ($sheet->getRowIterator() as $row) {
                $rowNum++;
                $cells = array_map(
                    fn ($c) => is_object($c) && method_exists($c, 'getValue') ? $c->getValue() : $c,
                    $row->getCells(),
                );

                if ($rowNum === 1) {
                    $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $cells);
                    continue;
                }

                // Fila totalmente vacia — skip
                $allEmpty = ! array_filter($cells, fn ($v) => $v !== null && $v !== '');
                if ($allEmpty) continue;

                $data = $this->rowToAssoc($headers, $cells);
                $rows[] = $this->validateRow($data, $rowNum, $companyId);
            }
            break; // solo procesamos la primera hoja Productos
        }
        $reader->close();

        if (! $productsSheetFound) {
            return [
                'rows' => [],
                'summary' => ['total' => 0, 'ok' => 0, 'errors' => 0, 'to_create' => 0, 'to_update' => 0],
                'valid' => false,
                'fatal' => 'No se encontró la hoja "Productos" en el archivo. Usa la plantilla oficial.',
            ];
        }

        // Segunda validacion: los variation_of_code deben apuntar a un
        // padre existente en DB o presente en el archivo.
        $codesInFile = collect($rows)->pluck('data.code')->filter()->values()->all();
        foreach ($rows as &$r) {
            $vof = $r['data']['variation_of_code'] ?? null;
            if (! $vof) continue;
            if (in_array($vof, $codesInFile, true)) continue;
            $exists = Product::query()
                ->where('company_id', $companyId)
                ->where('code', $vof)
                ->where('type', 'variable')
                ->exists();
            if (! $exists) {
                $r['errors'][] = "El padre '{$vof}' no existe ni viene en el archivo. Debe ser un producto tipo=variable ya creado o incluido en esta importación.";
            }
        }
        unset($r);

        // Detecta duplicados de code dentro del archivo
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
        return [
            'rows' => $rows,
            'summary' => $summary,
            'valid' => $summary['errors'] === 0 && $summary['total'] > 0,
        ];
    }

    /**
     * Importa las filas validadas. Debe llamarse solo si parseAndValidate
     * retorno valid=true. Dos pasadas: primero padres/simples, luego variantes.
     *
     * @return array{created:int, updated:int, errors:array}
     */
    public function import(array $rows, int $companyId): array
    {
        $created = 0;
        $updated = 0;
        $errors = [];

        // Filtra filas validas
        $valid = array_values(array_filter($rows, fn ($r) => empty($r['errors'])));

        // Pasada 1: padres/simples (no variantes)
        $pass1 = array_filter($valid, fn ($r) => empty($r['data']['variation_of_code']));
        // Pasada 2: variantes
        $pass2 = array_filter($valid, fn ($r) => ! empty($r['data']['variation_of_code']));

        DB::transaction(function () use (&$created, &$updated, &$errors, $pass1, $pass2, $companyId) {
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
        });

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /* ------------------------------------------------------------------ */

    protected function createOrUpdateProduct(array $data, int $companyId): bool
    {
        $existing = Product::query()
            ->where('company_id', $companyId)
            ->where('code', $data['code'])
            ->first();

        $payload = $this->buildPayload($data, $companyId);

        // Si es variante, agrega parent_product_id
        if (! empty($data['variation_of_code'])) {
            $parent = Product::query()
                ->where('company_id', $companyId)
                ->where('code', $data['variation_of_code'])
                ->where('type', 'variable')
                ->first();
            if (! $parent) {
                throw new \RuntimeException("Padre '{$data['variation_of_code']}' no existe.");
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

        if (! ($data['code'] ?? null)) $errors[] = 'code (SKU) es obligatorio';
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
        $this->categoryCache = [];
        $this->taxCache = [];
        $this->accountCache = [];
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
