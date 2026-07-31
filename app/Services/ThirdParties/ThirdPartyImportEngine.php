<?php

namespace App\Services\ThirdParties;

use App\Models\Account;
use App\Models\ThirdParty;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Motor de importacion masiva de terceros desde XLSX.
 *
 * Uso:
 *   $engine = app(ThirdPartyImportEngine::class);
 *   $preview = $engine->parseAndValidate($tmpPath, $companyId);
 *   $result = $engine->import($preview['rows'], $companyId);
 *
 * Match por (company_id, document_number) — existentes UPDATE, nuevos CREATE.
 * Cache de lookups (cuentas) para no hacer 1 query por celda.
 */
class ThirdPartyImportEngine
{
    /** @var array<string,int|null> */
    protected array $accountCache = [];

    /**
     * @return array{rows: array, summary: array, valid: bool}
     */
    public function parseAndValidate(string $filePath, int $companyId): array
    {
        $this->accountCache = [];

        $reader = new Reader();
        $reader->open($filePath);

        $rows = [];
        $rowNum = 0;
        $headers = null;
        $sheetFound = false;

        foreach ($reader->getSheetIterator() as $sheet) {
            $name = trim($sheet->getName());
            if (! preg_match('/^terceros/i', $name)) continue;
            $sheetFound = true;

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

                $allEmpty = ! array_filter($cells, fn ($v) => $v !== null && $v !== '');
                if ($allEmpty) continue;

                $data = $this->rowToAssoc($headers, $cells);
                $rows[] = $this->validateRow($data, $rowNum, $companyId);
            }
            break;
        }
        $reader->close();

        if (! $sheetFound) {
            return [
                'rows' => [],
                'summary' => ['total' => 0, 'ok' => 0, 'errors' => 0, 'to_create' => 0, 'to_update' => 0],
                'valid' => false,
                'fatal' => 'No se encontró la hoja "Terceros" en el archivo. Usa la plantilla oficial.',
            ];
        }

        // Detecta duplicados de document_number en el archivo
        $dupDocs = collect($rows)->pluck('data.document_number')
            ->filter()->countBy()->filter(fn ($n) => $n > 1)->keys()->all();
        if (! empty($dupDocs)) {
            foreach ($rows as &$r) {
                if (in_array($r['data']['document_number'] ?? null, $dupDocs, true)) {
                    $r['errors'][] = "document_number duplicado dentro del archivo: '{$r['data']['document_number']}'.";
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
     * @return array{created:int, updated:int, errors:array}
     */
    public function import(array $rows, int $companyId): array
    {
        $created = 0;
        $updated = 0;
        $errors = [];

        $valid = array_values(array_filter($rows, fn ($r) => empty($r['errors'])));

        DB::transaction(function () use (&$created, &$updated, &$errors, $valid, $companyId) {
            foreach ($valid as $r) {
                try {
                    $wasUpdate = $this->createOrUpdate($r['data'], $companyId);
                    $wasUpdate ? $updated++ : $created++;
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row_number' => $r['row_number'],
                        'document_number' => $r['data']['document_number'] ?? '',
                        'message' => $e->getMessage(),
                    ];
                }
            }
        });

        return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
    }

    /* ------------------------------------------------------------------ */

    protected function createOrUpdate(array $data, int $companyId): bool
    {
        $existing = ThirdParty::query()
            ->where('company_id', $companyId)
            ->where('document_number', $data['document_number'])
            ->first();

        $payload = $this->buildPayload($data, $companyId);

        if ($existing) {
            $existing->update($payload);
            return true;
        }
        ThirdParty::create(array_merge(['company_id' => $companyId], $payload));
        return false;
    }

    protected function buildPayload(array $data, int $companyId): array
    {
        // Tax responsibilities: string "O-13;O-15" -> array
        $taxResp = trim((string) ($data['tax_responsibilities'] ?? ''));
        $taxRespArray = $taxResp
            ? array_values(array_filter(array_map('trim', preg_split('/[;,\|]/', $taxResp))))
            : [];

        return [
            'document_number' => trim((string) $data['document_number']),
            'document_type' => strtolower(trim((string) $data['document_type'])),
            'person_type' => strtolower(trim((string) $data['person_type'])),
            'name' => trim((string) $data['name']),
            'legal_name' => $data['legal_name'] ?: null,
            'trade_name' => $data['trade_name'] ?: null,
            'first_name' => $data['first_name'] ?: null,
            'middle_name' => $data['middle_name'] ?: null,
            'last_name' => $data['last_name'] ?: null,
            'second_last_name' => $data['second_last_name'] ?: null,
            'dv' => $data['dv'] !== null && $data['dv'] !== '' ? (int) $data['dv'] : null,
            'is_customer' => $this->coerceBool($data['is_customer'], false),
            'is_supplier' => $this->coerceBool($data['is_supplier'], false),
            'is_employee' => $this->coerceBool($data['is_employee'], false),
            'is_other' => $this->coerceBool($data['is_other'], false),
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
            'mobile' => $data['mobile'] ?: null,
            'address' => $data['address'] ?: null,
            'city' => $data['city'] ?: null,
            'department' => $data['department'] ?: null,
            'country' => $data['country'] ?: 'CO',
            'postal_code' => $data['postal_code'] ?: null,
            'contact_person' => $data['contact_person'] ?: null,
            'contact_phone' => $data['contact_phone'] ?: null,
            'regime_type' => $data['regime_type'] ?: null,
            'tax_responsibilities' => $taxRespArray,
            'is_self_withholder' => $this->coerceBool($data['is_self_withholder'], false),
            'is_iva_withholder' => $this->coerceBool($data['is_iva_withholder'], false),
            'is_ica_withholder' => $this->coerceBool($data['is_ica_withholder'], false),
            'default_receivable_account_id' => $this->resolveAccountId($data['receivable_account_code'], $companyId),
            'default_payable_account_id' => $this->resolveAccountId($data['payable_account_code'], $companyId),
            'credit_limit' => $data['credit_limit'] !== null && $data['credit_limit'] !== ''
                ? (float) $data['credit_limit'] : 0,
            'credit_days' => (int) ($data['credit_days'] ?: 0),
            'payment_terms_days' => (int) ($data['payment_terms_days'] ?: 0),
            'website' => $data['website'] ?: null,
            'notes' => $data['notes'] ?: null,
            'active' => $this->coerceBool($data['active'], true),
        ];
    }

    /* ------------------------------------------------------------------ */

    protected function validateRow(array $data, int $rowNumber, int $companyId): array
    {
        $errors = [];

        if (! ($data['document_number'] ?? null)) {
            $errors[] = 'document_number es obligatorio';
        }
        if (! ($data['name'] ?? null)) {
            $errors[] = 'name es obligatorio';
        }

        $docType = strtolower(trim((string) ($data['document_type'] ?? '')));
        $data['document_type'] = $docType;
        if (! $docType) {
            $errors[] = 'document_type es obligatorio';
        } elseif (! array_key_exists($docType, ThirdParty::DOCUMENT_TYPES)) {
            $errors[] = "document_type '{$docType}' inválido (usa: ".implode(', ', array_keys(ThirdParty::DOCUMENT_TYPES)).')';
        }

        $personType = strtolower(trim((string) ($data['person_type'] ?? '')));
        $data['person_type'] = $personType;
        if (! $personType) {
            $errors[] = 'person_type es obligatorio (natural | juridica)';
        } elseif (! array_key_exists($personType, ThirdParty::PERSON_TYPES)) {
            $errors[] = "person_type '{$personType}' inválido (usa: natural | juridica)";
        }

        // Al menos un rol
        $hasRole = $this->coerceBool($data['is_customer'] ?? '', false)
            || $this->coerceBool($data['is_supplier'] ?? '', false)
            || $this->coerceBool($data['is_employee'] ?? '', false)
            || $this->coerceBool($data['is_other'] ?? '', false);
        if (! $hasRole) {
            $errors[] = 'Debe marcar al menos un rol: is_customer, is_supplier, is_employee o is_other';
        }

        // Regime type
        $regime = trim((string) ($data['regime_type'] ?? ''));
        if ($regime && ! array_key_exists($regime, ThirdParty::REGIME_TYPES)) {
            $errors[] = "regime_type '{$regime}' inválido (usa: ".implode(', ', array_keys(ThirdParty::REGIME_TYPES)).')';
        }

        // Tax responsibilities (validar cada código)
        $taxResp = trim((string) ($data['tax_responsibilities'] ?? ''));
        if ($taxResp) {
            $codes = array_map('trim', preg_split('/[;,\|]/', $taxResp));
            foreach ($codes as $c) {
                if ($c && ! array_key_exists($c, ThirdParty::TAX_RESPONSIBILITIES)) {
                    $errors[] = "tax_responsibilities: código '{$c}' inválido";
                }
            }
        }

        // NIT con DV
        if ($docType === 'nit' && ! empty($data['dv']) && ! is_numeric($data['dv'])) {
            $errors[] = "dv debe ser numérico (1-9)";
        }

        // Email
        if (! empty($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "email '{$data['email']}' no tiene formato válido";
        }

        // Cuentas
        if (! empty($data['receivable_account_code'])
            && $this->resolveAccountId($data['receivable_account_code'], $companyId) === null) {
            $errors[] = "receivable_account_code '{$data['receivable_account_code']}' no existe";
        }
        if (! empty($data['payable_account_code'])
            && $this->resolveAccountId($data['payable_account_code'], $companyId) === null) {
            $errors[] = "payable_account_code '{$data['payable_account_code']}' no existe";
        }

        // Numéricos
        foreach (['credit_limit', 'credit_days', 'payment_terms_days'] as $numCol) {
            $v = $data[$numCol] ?? null;
            if ($v !== null && $v !== '' && ! is_numeric($v)) {
                $errors[] = "{$numCol} debe ser numérico";
            }
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

        $existingDocs = ThirdParty::query()
            ->where('company_id', $companyId)
            ->whereIn('document_number', collect($rows)->pluck('data.document_number')->filter()->all())
            ->pluck('document_number')->all();

        foreach ($rows as $r) {
            if (! empty($r['errors'])) {
                $errors++;
                continue;
            }
            in_array($r['data']['document_number'] ?? null, $existingDocs, true)
                ? $toUpdate++
                : $toCreate++;
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

    protected function rowToAssoc(array $headers, array $cells): array
    {
        $out = [];
        foreach (ThirdPartyImportTemplateGenerator::COLUMNS as $col) {
            $idx = array_search($col, $headers, true);
            $out[$col] = $idx !== false ? ($cells[$idx] ?? null) : null;
        }
        // Strings triminados
        foreach ($out as $k => $v) {
            if (is_string($v)) $out[$k] = trim($v);
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

    protected function resolveAccountId(?string $code, int $companyId): ?int
    {
        if (! $code) return null;
        $code = trim($code);
        if ($code === '') return null;
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
