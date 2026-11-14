<?php

namespace App\Services\Audit;

/**
 * Homologación inteligente: campos que entiende el motor de auditoría
 * y reconocimiento automático de encabezados comunes.
 */
class ColumnMapper
{
    public const FIELDS = [
        'employeeId' => [
            'label' => 'Identificación del empleado',
            'aliases' => ['cedula', 'cédula', 'documento', 'identificacion', 'identificación', 'nit', 'id empleado', 'numero documento', 'no documento', 'cc'],
        ],
        'employee' => [
            'label' => 'Nombre del empleado',
            'aliases' => ['empleado', 'nombre', 'trabajador', 'nombre trabajador', 'colaborador', 'nombre empleado', 'nombres'],
        ],
        'concept' => [
            'label' => 'Concepto',
            'aliases' => ['concepto', 'descripcion', 'descripción', 'devengado', 'deduccion', 'deducción', 'novedad', 'nombre concepto'],
        ],
        'value' => [
            'label' => 'Valor',
            'aliases' => ['valor', 'total', 'importe', 'monto', 'liquidado', 'valor liquidado', 'neto'],
        ],
        'quantity' => [
            'label' => 'Cantidad',
            'aliases' => ['cantidad', 'horas', 'unidades', 'dias', 'días'],
        ],
    ];

    /** Normaliza texto: minúsculas, sin tildes y solo caracteres alfanuméricos. */
    public static function normalize(mixed $value): string
    {
        $text = mb_strtolower(trim((string) ($value ?? '')));
        $text = \Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text;
        $text = preg_replace('/\p{Mn}+/u', '', $text);

        return preg_replace('/[^a-z0-9]/', '', $text);
    }

    /** Sugiere el campo para un encabezado según los alias conocidos. */
    public static function guess(string $header): string
    {
        $normalized = self::normalize($header);

        foreach (self::FIELDS as $field => $definition) {
            foreach ($definition['aliases'] as $alias) {
                if (self::normalize($alias) === $normalized) {
                    return $field;
                }
            }
        }

        return '';
    }

    /**
     * Construye la homologación de un archivo: primero la guardada para la empresa,
     * luego la sugerencia automática.
     *
     * @param  list<string>  $headers
     * @param  array<string,string>  $saved
     * @return array<string,string>
     */
    public static function suggest(array $headers, array $saved): array
    {
        $mapping = [];
        foreach ($headers as $header) {
            $mapping[$header] = array_key_exists($header, $saved) && $saved[$header] !== ''
                ? $saved[$header]
                : self::guess($header);
        }

        return $mapping;
    }

    /**
     * Convierte las filas crudas del archivo a filas con campos estándar.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,string>  $mapping
     * @return list<array<string,mixed>>
     */
    public static function apply(array $rows, array $mapping): array
    {
        $mapping = array_filter($mapping, fn ($field) => isset(self::FIELDS[$field]));

        return array_map(function (array $row) use ($mapping) {
            $out = [];
            foreach ($mapping as $header => $field) {
                $out[$field] = $row[$header] ?? '';
            }

            return $out;
        }, $rows);
    }

    /**
     * Interpreta montos con formato colombiano o internacional:
     * "$ 1.250.000", "1.250.000,50", "1,250,000.50", 1250000.
     */
    public static function toNumber(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = preg_replace('/[\s$]|COP/i', '', (string) ($value ?? ''));
        if ($text === '' || $text === '-') {
            return 0.0;
        }

        $negative = str_starts_with($text, '-') || (str_starts_with($text, '(') && str_ends_with($text, ')'));
        $text = trim($text, '-()');

        $lastDot = strrpos($text, '.');
        $lastComma = strrpos($text, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // El último separador es el decimal.
            $decimal = $lastDot > $lastComma ? '.' : ',';
            $thousands = $decimal === '.' ? ',' : '.';
            $text = str_replace([$thousands, $decimal], ['', '.'], $text);
        } elseif ($lastComma !== false) {
            $text = preg_match('/^\d{1,3}(,\d{3})+$/', $text) ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        } elseif ($lastDot !== false) {
            $text = preg_match('/^\d{1,3}(\.\d{3})+$/', $text) ? str_replace('.', '', $text) : $text;
        }

        $number = is_numeric($text) ? (float) $text : 0.0;

        return $negative ? -$number : $number;
    }
}
