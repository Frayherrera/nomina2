<?php

namespace App\Services\Audit;

/**
 * Motor de auditoría de nómina.
 *
 * Toma el archivo principal como referencia y lo contrasta con cada archivo
 * de comparación por la llave (identificación del empleado + concepto):
 *  - Registros duplicados en el archivo principal.
 *  - Registros del comparativo que no existen en el principal.
 *  - Registros del principal que no aparecen en el comparativo.
 *  - Diferencias de valor (> 0,01) o de cantidad.
 */
class PayrollAuditEngine
{
    private const STATUS = 'Revisión manual';

    /**
     * @param  list<array<string,mixed>>  $mainRows  Filas homologadas del archivo principal.
     * @param  list<array{name:string, rows:list<array<string,mixed>>}>  $comparisons
     * @return list<array<string,mixed>>
     */
    public function run(array $mainRows, string $mainFileName, array $comparisons): array
    {
        $findings = [];
        $main = [];

        foreach ($mainRows as $row) {
            if (ColumnMapper::normalize($row['employeeId'] ?? '') === '') {
                continue;
            }
            $key = $this->key($row);

            if (isset($main[$key])) {
                $value = ColumnMapper::toNumber($row['value'] ?? '');
                $findings[] = $this->finding('duplicate', $row, $mainFileName, (string) $value, (string) $value, 0,
                    'Registro duplicado en el archivo principal.',
                    'Verificar si corresponde a una novedad repetida o a dos conceptos diferentes.');

                continue;
            }

            $main[$key] = $row;
        }

        // La cantidad solo se compara si ambos archivos tienen esa columna homologada.
        $mainHasQuantity = $this->hasField($mainRows, 'quantity');

        foreach ($comparisons as $comparison) {
            $seen = [];
            $compareQuantity = $mainHasQuantity && $this->hasField($comparison['rows'], 'quantity');

            foreach ($comparison['rows'] as $row) {
                if (ColumnMapper::normalize($row['employeeId'] ?? '') === '') {
                    continue;
                }
                $key = $this->key($row);
                $seen[$key] = true;

                if (! isset($main[$key])) {
                    $findings[] = $this->finding('missing_main', $row, $comparison['name'], 'No existe',
                        (string) ColumnMapper::toNumber($row['value'] ?? ''), 0,
                        'El registro aparece en el archivo comparativo, pero no se encontró en el archivo principal.',
                        'Verificar la vinculación del empleado y el concepto antes de registrar o eliminar información.');

                    continue;
                }

                $reference = $main[$key];
                $mainValue = ColumnMapper::toNumber($reference['value'] ?? '');
                $comparedValue = ColumnMapper::toNumber($row['value'] ?? '');
                $valueDiffers = abs($mainValue - $comparedValue) > 0.01;
                $quantityDiffers = $compareQuantity && ! $this->sameQuantity($reference['quantity'] ?? '', $row['quantity'] ?? '');

                if (! $valueDiffers && ! $quantityDiffers) {
                    continue;
                }

                $merged = [
                    'employee' => ($reference['employee'] ?? '') ?: ($row['employee'] ?? ''),
                    'employeeId' => $row['employeeId'],
                    'concept' => ($reference['concept'] ?? '') ?: ($row['concept'] ?? ''),
                ];

                $findings[] = $this->finding(
                    $valueDiffers ? 'difference' : 'quantity',
                    $merged,
                    $comparison['name'],
                    (string) $mainValue,
                    (string) $comparedValue,
                    round($comparedValue - $mainValue, 2),
                    $valueDiffers
                        ? 'El valor del concepto difiere en '.self::money(abs($comparedValue - $mainValue)).' frente al archivo principal.'
                        : 'La cantidad registrada no coincide con el archivo principal.',
                    'Revisar el soporte del concepto y confirmar cuál registro refleja la liquidación correcta.'
                );
            }

            foreach ($main as $key => $row) {
                if (isset($seen[$key])) {
                    continue;
                }
                $findings[] = $this->finding('missing_compare', $row, $comparison['name'],
                    (string) ColumnMapper::toNumber($row['value'] ?? ''), 'No existe', 0,
                    'El registro del archivo principal no aparece en este archivo comparativo.',
                    'Confirmar si el archivo comparativo debe contener este empleado y concepto.');
            }
        }

        foreach ($findings as $i => &$finding) {
            $finding['code'] = 'HALL-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
        }

        return $findings;
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array{total:int, differences:int, missing:int, duplicates:int}
     */
    public static function summarize(array $findings): array
    {
        $count = fn (callable $fn) => count(array_filter($findings, $fn));

        return [
            'total' => count($findings),
            'differences' => $count(fn ($f) => (float) $f['difference'] != 0.0),
            'missing' => $count(fn ($f) => in_array($f['type'], ['missing_main', 'missing_compare'], true)),
            'duplicates' => $count(fn ($f) => $f['type'] === 'duplicate'),
        ];
    }

    public static function money(float $amount): string
    {
        return '$ '.number_format($amount, 0, ',', '.');
    }

    private function key(array $row): string
    {
        return ColumnMapper::normalize($row['employeeId'] ?? '').'|'.ColumnMapper::normalize($row['concept'] ?? '');
    }

    /** ColumnMapper::apply crea la llave para cada campo homologado. */
    private function hasField(array $rows, string $field): bool
    {
        return $rows !== [] && array_key_exists($field, $rows[0]);
    }

    private function sameQuantity(mixed $a, mixed $b): bool
    {
        $emptyA = trim((string) $a) === '';
        $emptyB = trim((string) $b) === '';
        if ($emptyA || $emptyB) {
            return $emptyA === $emptyB;
        }

        return abs(ColumnMapper::toNumber($a) - ColumnMapper::toNumber($b)) < 0.0001;
    }

    private function finding(string $type, array $row, string $fileName, string $mainValue, string $comparedValue,
        float $difference, string $description, string $suggestion): array
    {
        return [
            'code' => '',
            'type' => $type,
            'employee' => trim((string) ($row['employee'] ?? '')) ?: 'Sin nombre',
            'employee_id' => trim((string) ($row['employeeId'] ?? '')),
            'concept' => trim((string) ($row['concept'] ?? '')) ?: 'Sin concepto',
            'file_name' => $fileName,
            'main_value' => $mainValue,
            'compared_value' => $comparedValue,
            'difference' => $difference,
            'description' => $description,
            'suggestion' => $suggestion,
            'status' => self::STATUS,
        ];
    }
}
