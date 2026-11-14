<?php

namespace App\Services\Audit;

/**
 * Motor de auditoría de nómina.
 *
 * Toma el archivo principal como referencia y lo contrasta con cada archivo de comparación
 * por la llave (identificación del empleado + concepto):
 *  - Registros duplicados en el archivo principal.
 *  - Empleados que están en un archivo y no en el otro (un hallazgo por empleado).
 *  - Nombres del empleado que no coinciden entre archivos.
 *  - Conceptos faltantes para un empleado (solo si el otro archivo maneja ese concepto).
 *  - Diferencias de valor por encima de la tolerancia de redondeo, o de cantidad.
 */
class PayrollAuditEngine
{
    private const STATUS = 'Revisión manual';

    private const WHOLE_EMPLOYEE = 'Empleado completo';

    /**
     * @param  list<array<string,mixed>>  $mainRows  Filas homologadas del archivo principal.
     * @param  list<array{name:string, rows:list<array<string,mixed>>, tolerance?:float}>  $comparisons
     * @return list<array<string,mixed>>
     */
    public function run(array $mainRows, string $mainFileName, array $comparisons): array
    {
        $findings = [];
        $main = [];
        $mainEmployees = [];
        $mainConcepts = [];

        foreach ($mainRows as $row) {
            $id = ColumnMapper::normalizeId($row['employeeId'] ?? '');
            if ($id === '') {
                continue;
            }
            $mainEmployees[$id] ??= trim((string) ($row['employee'] ?? ''));
            $concept = ColumnMapper::normalize($row['concept'] ?? '');
            $mainConcepts[$concept] = true;
            $key = $id.'|'.$concept;

            if (isset($main[$key])) {
                $value = ColumnMapper::toNumber($row['value'] ?? '');
                $findings[] = $this->finding('duplicate', $row, $mainFileName, (string) $value, (string) $value, 0,
                    'Registro duplicado en el archivo principal.',
                    'Verificar si corresponde a una novedad repetida o a dos conceptos diferentes.');

                continue;
            }

            $main[$key] = $row;
        }

        $mainHasQuantity = $this->hasField($mainRows, 'quantity');

        foreach ($comparisons as $comparison) {
            $file = $comparison['name'];
            $tolerance = max(0.01, (float) ($comparison['tolerance'] ?? 0.01));
            $compareQuantity = $mainHasQuantity && $this->hasField($comparison['rows'], 'quantity');

            // Empleados y conceptos presentes en el comparativo.
            $employees = [];
            $concepts = [];
            foreach ($comparison['rows'] as $row) {
                $id = ColumnMapper::normalizeId($row['employeeId'] ?? '');
                if ($id !== '') {
                    $employees[$id] ??= $row;
                    $concepts[ColumnMapper::normalize($row['concept'] ?? '')] = true;
                }
            }

            // Nombres que no coinciden.
            foreach ($employees as $id => $row) {
                $mainName = $mainEmployees[$id] ?? null;
                $otherName = trim((string) ($row['employee'] ?? ''));
                if ($mainName && $otherName !== '' && ColumnMapper::normalize($mainName) !== ColumnMapper::normalize($otherName)) {
                    $findings[] = $this->finding('name', ['employee' => $mainName, 'employeeId' => $row['employeeId'], 'concept' => 'Nombre del empleado'],
                        $file, $mainName, $otherName, 0,
                        'El nombre del empleado no coincide con el archivo principal.',
                        'Confirmar el nombre correcto según el documento de identidad y corregirlo en el archivo que corresponda.');
                }
            }

            // Empleados del comparativo que no están en el principal.
            foreach ($employees as $id => $row) {
                if (! isset($mainEmployees[$id])) {
                    $findings[] = $this->finding('missing_main', ['employee' => $row['employee'] ?? '', 'employeeId' => $row['employeeId'], 'concept' => self::WHOLE_EMPLOYEE],
                        $file, 'No existe', 'Registrado', 0,
                        'El empleado aparece en el archivo comparativo, pero no se encontró en el archivo principal.',
                        'Verificar la vinculación del empleado antes de registrar o eliminar información.');
                }
            }

            $seen = [];
            foreach ($comparison['rows'] as $row) {
                $id = ColumnMapper::normalizeId($row['employeeId'] ?? '');
                if ($id === '' || ! isset($mainEmployees[$id])) {
                    continue;
                }

                $concept = ColumnMapper::normalize($row['concept'] ?? '');
                $label = trim((string) ($row['concept'] ?? ''));
                if (! isset($mainConcepts[$concept])) {
                    // Concepto que el principal no maneja: se compara contra su equivalente, si existe.
                    $fallback = ColumnMapper::CONCEPTS[$row['code'] ?? '']['fallback'] ?? null;
                    $fallbackLabel = $fallback ? ColumnMapper::CONCEPTS[$fallback]['label'] : null;
                    if (! $fallbackLabel || ! isset($mainConcepts[ColumnMapper::normalize($fallbackLabel)])) {
                        continue;
                    }
                    $label = "$label vs. $fallbackLabel";
                    $concept = ColumnMapper::normalize($fallbackLabel);
                }

                $key = $id.'|'.$concept;
                $seen[$key] = true;

                if (! isset($main[$key])) {
                    $findings[] = $this->finding('missing_main', $row + ['concept' => $label], $file, 'No existe',
                        (string) ColumnMapper::toNumber($row['value'] ?? ''), 0,
                        'El concepto aparece en el archivo comparativo, pero no se encontró para este empleado en el archivo principal.',
                        'Verificar la novedad del empleado antes de registrar o eliminar información.');

                    continue;
                }

                $reference = $main[$key];
                $mainValue = ColumnMapper::toNumber($reference['value'] ?? '');
                $comparedValue = ColumnMapper::toNumber($row['value'] ?? '');
                $valueDiffers = abs($mainValue - $comparedValue) >= $tolerance;
                $quantityDiffers = $compareQuantity && ! $this->sameQuantity($reference['quantity'] ?? '', $row['quantity'] ?? '');

                if (! $valueDiffers && ! $quantityDiffers) {
                    continue;
                }

                $findings[] = $this->finding(
                    $valueDiffers ? 'difference' : 'quantity',
                    [
                        'employee' => ($reference['employee'] ?? '') ?: ($row['employee'] ?? ''),
                        'employeeId' => $row['employeeId'],
                        'concept' => str_contains($label, ' vs. ') ? $label : (($reference['concept'] ?? '') ?: $label),
                    ],
                    $file,
                    (string) $mainValue,
                    (string) $comparedValue,
                    round($comparedValue - $mainValue, 2),
                    $valueDiffers
                        ? 'El valor del concepto difiere en '.self::money(abs($comparedValue - $mainValue)).' frente al archivo principal.'
                        : 'La cantidad registrada no coincide con el archivo principal.',
                    'Revisar el soporte del concepto y confirmar cuál registro refleja la liquidación correcta.'
                );
            }

            // Registros del principal que no aparecen en el comparativo.
            $reported = [];
            foreach ($main as $key => $row) {
                if (isset($seen[$key])) {
                    continue;
                }
                [$id, $concept] = explode('|', $key, 2);

                if (! isset($employees[$id])) {
                    if (! isset($reported[$id])) {
                        $reported[$id] = true;
                        $findings[] = $this->finding('missing_compare', ['employee' => $row['employee'] ?? '', 'employeeId' => $row['employeeId'], 'concept' => self::WHOLE_EMPLOYEE],
                            $file, 'Registrado', 'No existe', 0,
                            'El empleado del archivo principal no aparece en este archivo comparativo.',
                            'Confirmar si el archivo comparativo debe incluir a este empleado.');
                    }

                    continue;
                }

                if (! isset($concepts[$concept]) && ! $this->reachedByFallback($concept, $seen)) {
                    continue; // El comparativo no maneja este concepto.
                }

                $findings[] = $this->finding('missing_compare', $row, $file,
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
        $decimals = abs($amount - round($amount)) >= 0.005 ? 2 : 0;

        return '$ '.number_format($amount, $decimals, ',', '.');
    }

    /** Un concepto del principal pudo compararse por equivalencia (p. ej. Sueldo ← IBC salud). */
    private function reachedByFallback(string $concept, array $seen): bool
    {
        foreach (array_keys($seen) as $key) {
            if (str_ends_with($key, '|'.$concept)) {
                return true;
            }
        }

        return false;
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
            'employee_id' => ColumnMapper::normalizeId($row['employeeId'] ?? '') ?: trim((string) ($row['employeeId'] ?? '')),
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
