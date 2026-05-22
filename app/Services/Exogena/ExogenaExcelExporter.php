<?php

namespace App\Services\Exogena;

use App\Models\ThirdParty;
use App\Support\SimpleXlsxWriter;

/**
 * Exporta un formato de información exógena a un archivo .xlsx con la
 * estructura estándar (identificación del tercero + valor), listo para
 * trabajar / cargar en el prevalidador DIAN.
 *
 * Aplica la regla de cuantías mínimas: los terceros cuyo acumulado del
 * formato es menor al tope se agrupan en un registro "CUANTÍAS MENORES"
 * (tipo documento 43, número 222222222).
 */
class ExogenaExcelExporter
{
    public function __construct(protected ExogenaEngine $engine) {}

    /** Códigos DIAN de tipo de documento de identificación. */
    protected const DIAN_DOC_TYPES = [
        'cc' => '13',
        'ce' => '22',
        'ti' => '12',
        'nit' => '31',
        'pasaporte' => '41',
        'rut' => '31',
        'nuip' => '91',
        'die' => '42',
    ];

    protected const HEADER = [
        'Formato', 'Concepto', 'Tipo documento (DIAN)', 'Número identificación', 'DV',
        'Primer apellido', 'Segundo apellido', 'Primer nombre', 'Otros nombres',
        'Razón social', 'Dirección', 'Departamento', 'País', 'Valor',
    ];

    /**
     * @return array{filename:string, content:string}
     */
    public function export(string $formatCode, int $year, float $threshold = 0): array
    {
        $engineRows = $this->engine->build($formatCode, $year);

        $partyIds = collect($engineRows)->pluck('third_party_id')->filter()->unique()->all();
        $parties = ThirdParty::query()->whereIn('id', $partyIds)->get()->keyBy('id');

        // Cuantías mínimas: terceros cuyo acumulado (todos los conceptos)
        // queda bajo el tope se reportan agrupados.
        $belowThreshold = [];
        if ($threshold > 0) {
            $totals = [];
            foreach ($engineRows as $row) {
                $key = $this->partyKey($row);
                $totals[$key] = ($totals[$key] ?? 0) + $row['amount'];
            }
            foreach ($totals as $key => $total) {
                if (abs($total) < $threshold) {
                    $belowThreshold[$key] = true;
                }
            }
        }

        $dataRows = [];
        $minorByConcept = [];

        foreach ($engineRows as $row) {
            if (isset($belowThreshold[$this->partyKey($row)])) {
                $minorByConcept[$row['concept_code']] = [
                    'name' => $row['concept_name'],
                    'amount' => ($minorByConcept[$row['concept_code']]['amount'] ?? 0) + $row['amount'],
                ];
                continue;
            }

            $party = $row['third_party_id'] ? $parties->get($row['third_party_id']) : null;
            $dataRows[] = $this->buildRow($formatCode, $row, $party);
        }

        // Registros agrupados de cuantías menores (uno por concepto).
        foreach ($minorByConcept as $conceptCode => $minor) {
            if (round($minor['amount'], 2) == 0.0) {
                continue;
            }
            $dataRows[] = [
                $formatCode,
                $conceptCode.' — '.$minor['name'],
                '43', '222222222', '',
                '', '', '', '',
                'CUANTÍAS MENORES',
                '', '', 'CO',
                round($minor['amount'], 2),
            ];
        }

        $xlsxRows = array_merge([self::HEADER], $dataRows);
        $content = SimpleXlsxWriter::build($xlsxRows, 'Formato '.$formatCode);

        return [
            'filename' => "exogena_{$formatCode}_{$year}.xlsx",
            'content' => $content,
        ];
    }

    /** Clave para acumular por tercero (id real, o nombre si no tiene id). */
    protected function partyKey(array $row): string
    {
        return $row['third_party_id']
            ? 'id:'.$row['third_party_id']
            : 'name:'.$row['third_party'];
    }

    /**
     * Una fila del Excel: identificación del tercero + concepto + valor.
     *
     * @return array<int,string|float>
     */
    protected function buildRow(string $formatCode, array $row, ?ThirdParty $party): array
    {
        $concept = $row['concept_code'].' — '.$row['concept_name'];

        if ($party) {
            $isJuridica = $party->person_type === 'juridica';

            return [
                $formatCode,
                $concept,
                self::DIAN_DOC_TYPES[$party->document_type] ?? '',
                (string) $party->document_number,
                (string) ($party->dv ?? ''),
                $isJuridica ? '' : (string) ($party->last_name ?? ''),
                $isJuridica ? '' : (string) ($party->second_last_name ?? ''),
                $isJuridica ? '' : (string) ($party->first_name ?? ''),
                $isJuridica ? '' : (string) ($party->middle_name ?? ''),
                $isJuridica ? (string) ($party->legal_name ?: $party->name) : '',
                (string) ($party->address ?? ''),
                (string) ($party->department ?? ''),
                (string) ($party->country ?: 'CO'),
                (float) $row['amount'],
            ];
        }

        // Sin ThirdParty enlazado (socios del formato 1010, o sin tercero).
        $docType = isset($row['document_type'])
            ? (self::DIAN_DOC_TYPES[$row['document_type']] ?? '')
            : '';

        return [
            $formatCode,
            $concept,
            $docType,
            (string) ($row['document_number'] ?? ''),
            '',
            '', '', '', '',
            (string) $row['third_party'],
            '', '', 'CO',
            (float) $row['amount'],
        ];
    }
}
