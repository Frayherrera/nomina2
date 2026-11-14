<?php

namespace App\Services\Audit;

/**
 * Homologación inteligente de columnas.
 *
 * Soporta dos formas de archivo:
 *  - Por columnas (lo usual en Colombia: comprobante de nómina, SIIGO, PILA): una fila por
 *    empleado y una columna por concepto. Cada columna se homologa a un concepto estándar
 *    ("c:sueldo", "c:neto", ...).
 *  - Por filas: una fila por empleado y concepto, con columnas Concepto / Valor / Cantidad.
 */
class ColumnMapper
{
    /** Datos del empleado y campos del formato por filas. */
    public const FIELDS = [
        'employeeId' => [
            'label' => 'Identificación del empleado',
            'aliases' => ['cedula', 'cédula', 'documento', 'identificacion', 'identificación', 'nit', 'id empleado',
                'numero documento', 'no documento', 'n° documento', 'nro documento', 'numero de documento',
                'numero de identificacion', 'documento de identidad', 'cc', 'codigo empleado'],
        ],
        'employee' => [
            'label' => 'Nombre del empleado',
            'aliases' => ['empleado', 'nombre', 'trabajador', 'nombre trabajador', 'colaborador', 'nombre empleado',
                'nombres', 'apellidos y nombres', 'nombre completo', 'nombres y apellidos'],
        ],
        'concept' => [
            'label' => 'Concepto (formato por filas)',
            'aliases' => ['concepto', 'descripcion', 'descripción', 'novedad', 'nombre concepto', 'concepto nomina'],
        ],
        'value' => [
            'label' => 'Valor (formato por filas)',
            'aliases' => ['valor', 'importe', 'monto', 'liquidado', 'valor liquidado', 'total'],
        ],
        'quantity' => [
            'label' => 'Cantidad (formato por filas)',
            'aliases' => ['cantidad', 'horas', 'unidades', 'dias', 'días'],
        ],
    ];

    /**
     * Conceptos estándar para archivos por columnas.
     * pattern: expresión sobre el encabezado normalizado (sin tildes ni espacios).
     * sum: varias columnas del mismo archivo se suman (p. ej. Préstamo + Anticipo).
     * fallback: concepto del archivo principal con el que se compara si este no existe allí.
     */
    public const CONCEPTS = [
        'salario_base' => ['label' => 'Salario básico', 'group' => 'Devengos', 'pattern' => '/^salario(basico|base|mensual)$/'],
        'dias' => ['label' => 'Días trabajados / cotizados', 'group' => 'Devengos', 'pattern' => '/^dias(trabajados|laborados|cotizados|liquidados)?$/'],
        'sueldo' => ['label' => 'Sueldo devengado', 'group' => 'Devengos', 'pattern' => '/^(sueldo|totalsalario|salariodevengado|sueldodevengado|salario|sueldobasico)$/'],
        'aux_transporte' => ['label' => 'Auxilio de transporte', 'group' => 'Devengos', 'pattern' => '/transporte|conectividad/'],
        'extras' => ['label' => 'Horas extras y recargos', 'group' => 'Devengos', 'pattern' => '/extra|recargo/'],
        'bonificaciones' => ['label' => 'Bonificaciones', 'group' => 'Devengos', 'pattern' => '/bonificacion/'],
        'comisiones' => ['label' => 'Comisiones', 'group' => 'Devengos', 'pattern' => '/comision/'],
        'aux_alimentacion' => ['label' => 'Auxilio de alimentación', 'group' => 'Devengos', 'pattern' => '/alimentacion/'],
        'no_salarial' => ['label' => 'Pagos no constitutivos de salario', 'group' => 'Devengos', 'pattern' => '/con?stitutivo|nosalarial/'],
        'total_devengado' => ['label' => 'Total devengado', 'group' => 'Devengos', 'pattern' => '/^total(remunerado|ingresos|devengado|devengos)$/'],

        'salud_empleado' => ['label' => 'Salud (aporte empleado)', 'group' => 'Deducciones', 'pattern' => '/^(salud\d*|fondodesalud|aportesaludempleado|deduccionsalud|descuentosalud|eps)$/'],
        'pension_empleado' => ['label' => 'Pensión (aporte empleado)', 'group' => 'Deducciones', 'pattern' => '/^(pension\d*|fondodepension|aportepensionempleado|deduccionpension|descuentopension|afp)$/'],
        'fsp' => ['label' => 'Fondo de solidaridad pensional', 'group' => 'Deducciones', 'pattern' => '/solidaridad|^fsp$/'],
        'prestamos' => ['label' => 'Préstamos y anticipos', 'group' => 'Deducciones', 'pattern' => '/prestamo|anticipo|libranza/', 'sum' => true],
        'retencion' => ['label' => 'Retención en la fuente', 'group' => 'Deducciones', 'pattern' => '/retencion/'],
        'otros_descuentos' => ['label' => 'Otros descuentos', 'group' => 'Deducciones', 'pattern' => '/^otros(descuentos|deducciones)?$/', 'sum' => true],
        'total_deducciones' => ['label' => 'Total deducciones', 'group' => 'Deducciones', 'pattern' => '/^total(descuentos|deducciones)$/'],
        'neto' => ['label' => 'Neto a pagar', 'group' => 'Deducciones', 'pattern' => '/neto/'],

        'ibc_salud' => ['label' => 'IBC salud', 'group' => 'Seguridad social', 'pattern' => '/^ibcsalud(empleado)?$/', 'fallback' => 'sueldo'],
        'ibc_pension' => ['label' => 'IBC pensión', 'group' => 'Seguridad social', 'pattern' => '/^ibcpension(empleado)?$/'],
        'ibc_arl' => ['label' => 'IBC riesgos (ARL)', 'group' => 'Seguridad social', 'pattern' => '/^ibc(arl|riesgos)$/'],
        'ibc_caja' => ['label' => 'IBC caja / parafiscales', 'group' => 'Seguridad social', 'pattern' => '/^ibc(caja|cajas|parafiscales|ccf)$/'],
        'aporte_salud' => ['label' => 'Aporte total salud', 'group' => 'Seguridad social', 'pattern' => '/^aportes?salud$/', 'fallback' => 'salud_empleado'],
        'aporte_salud_empresa' => ['label' => 'Aporte salud empresa', 'group' => 'Seguridad social', 'pattern' => '/^aportesaludempresa$/'],
        'aporte_pension' => ['label' => 'Aporte total pensión', 'group' => 'Seguridad social', 'pattern' => '/^aportes?pension$/'],
        'aporte_pension_empresa' => ['label' => 'Aporte pensión empresa', 'group' => 'Seguridad social', 'pattern' => '/^aportepensionempresa$/'],
        'aporte_arl' => ['label' => 'Aporte riesgos (ARL)', 'group' => 'Seguridad social', 'pattern' => '/^(arlempresa|aportes?riesgos|aportearl|arl)$/'],
        'aporte_caja' => ['label' => 'Aporte caja de compensación', 'group' => 'Seguridad social', 'pattern' => '/^(cajadecompensacion|aportes?caja|caja|aporteccf|ccf)$/'],
        'aporte_sena' => ['label' => 'Aporte SENA', 'group' => 'Seguridad social', 'pattern' => '/^(aportes?)?sena$/'],
        'aporte_icbf' => ['label' => 'Aporte ICBF', 'group' => 'Seguridad social', 'pattern' => '/^(aportes?)?icbf$/'],
        'total_aportes' => ['label' => 'Total aportes seguridad social', 'group' => 'Seguridad social', 'pattern' => '/^totalaportes(seguridadsocial)?$/'],

        'prima' => ['label' => 'Prima de servicios', 'group' => 'Prestaciones sociales', 'pattern' => '/^prima(de)?(servicios?)?$/'],
        'cesantias' => ['label' => 'Cesantías', 'group' => 'Prestaciones sociales', 'pattern' => '/^cesantias$/'],
        'int_cesantias' => ['label' => 'Intereses sobre cesantías', 'group' => 'Prestaciones sociales', 'pattern' => '/^(intereses|int)(de|sobre)?cesantias$/'],
        'vacaciones' => ['label' => 'Vacaciones', 'group' => 'Prestaciones sociales', 'pattern' => '/^vacaciones$/'],
        'total_prestaciones' => ['label' => 'Total prestaciones', 'group' => 'Prestaciones sociales', 'pattern' => '/^totalprestaciones$/'],
    ];

    /** Normaliza texto: minúsculas, sin tildes y solo caracteres alfanuméricos. */
    public static function normalize(mixed $value): string
    {
        $text = mb_strtolower(trim((string) ($value ?? '')));
        $text = \Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text;
        $text = preg_replace('/\p{Mn}+/u', '', $text);

        return preg_replace('/[^a-z0-9]/', '', $text);
    }

    /** Normaliza una identificación: "CC 1.064.836.525" y 1064836525.0 → "1064836525". */
    public static function normalizeId(mixed $value): string
    {
        if (is_float($value) && floor($value) === $value) {
            $value = number_format($value, 0, '', '');
        }
        $text = self::normalize($value);
        $text = preg_replace('/^(cc|ce|ti|pa|pe|pt|nit|rc|cd|sc)(?=\d)/', '', $text);

        return ltrim($text, '0') ?: $text;
    }

    /** ¿El valor es una opción válida de homologación? */
    public static function isValid(string $field): bool
    {
        return isset(self::FIELDS[$field])
            || (str_starts_with($field, 'c:') && isset(self::CONCEPTS[substr($field, 2)]));
    }

    public static function label(string $field): string
    {
        if (str_starts_with($field, 'c:')) {
            return self::CONCEPTS[substr($field, 2)]['label'] ?? $field;
        }

        return self::FIELDS[$field]['label'] ?? $field;
    }

    /**
     * Opciones agrupadas para el selector de homologación.
     *
     * @return array<string, array<string,string>>
     */
    public static function options(): array
    {
        $groups = [
            'Datos del empleado' => [
                'employeeId' => self::FIELDS['employeeId']['label'],
                'employee' => self::FIELDS['employee']['label'],
            ],
        ];
        foreach (self::CONCEPTS as $code => $concept) {
            $groups['Concepto · '.$concept['group']]['c:'.$code] = $concept['label'];
        }
        $groups['Formato por filas'] = [
            'concept' => self::FIELDS['concept']['label'],
            'value' => self::FIELDS['value']['label'],
            'quantity' => self::FIELDS['quantity']['label'],
        ];

        return $groups;
    }

    private static function matchesAlias(string $field, string $normalized): bool
    {
        foreach (self::FIELDS[$field]['aliases'] as $alias) {
            if (self::normalize($alias) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /** Parte del encabezado sin el prefijo de hoja ("Prestaciones · Prima" → "Prima"). */
    public static function baseHeader(string $header): string
    {
        $pos = mb_strrpos($header, ' · ');

        return $pos === false ? $header : mb_substr($header, $pos + 3);
    }

    /** Sugiere el campo para un encabezado aislado. */
    public static function guess(string $header, bool $rowFormat = false): string
    {
        $normalized = self::normalize(self::baseHeader($header));

        foreach (['employeeId', 'employee'] as $field) {
            if (self::matchesAlias($field, $normalized)) {
                return $field;
            }
        }

        if ($rowFormat) {
            foreach (['concept', 'value', 'quantity'] as $field) {
                if (self::matchesAlias($field, $normalized)) {
                    return $field;
                }
            }

            return '';
        }

        foreach (self::CONCEPTS as $code => $concept) {
            if (preg_match($concept['pattern'], $normalized)) {
                return 'c:'.$code;
            }
        }

        return '';
    }

    /**
     * Construye la homologación de un archivo: primero la guardada para la empresa y luego
     * la sugerencia automática. Cada concepto se asigna una sola vez por archivo (salvo los
     * que se suman), así una columna repetida en otra hoja no se duplica.
     *
     * @param  list<string>  $headers
     * @param  array<string,string>  $saved
     * @return array<string,string>
     */
    public static function suggest(array $headers, array $saved): array
    {
        $rowFormat = collect($headers)->contains(fn ($h) => self::matchesAlias('concept', self::normalize(self::baseHeader($h))));

        $mapping = [];
        $used = [];
        foreach ($headers as $header) {
            // Lo que el auditor guardó para la empresa (incluido "No utilizar") se respeta tal cual.
            if (array_key_exists($header, $saved) && ($saved[$header] === '' || self::isValid((string) $saved[$header]))) {
                $mapping[$header] = $field = (string) $saved[$header];
                $used[$field] = true;

                continue;
            }

            $field = self::guess($header, $rowFormat);
            $summable = str_starts_with($field, 'c:') && (self::CONCEPTS[substr($field, 2)]['sum'] ?? false);
            if ($field !== '' && isset($used[$field]) && ! $summable) {
                $field = '';
            }
            if ($field !== '') {
                $used[$field] = true;
            }
            $mapping[$header] = $field;
        }

        return $mapping;
    }

    /**
     * Convierte las filas del archivo a filas estándar {employeeId, employee, concept, value[, quantity]}.
     * En formato por columnas genera una fila por empleado y concepto (celdas vacías = 0;
     * columnas homologadas al mismo concepto se suman).
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,string>  $mapping
     * @return list<array<string,mixed>>
     */
    public static function apply(array $rows, array $mapping): array
    {
        $mapping = array_filter($mapping, fn ($field) => self::isValid((string) $field));
        $find = fn (string $field) => array_keys($mapping, $field, true);

        $idColumns = $find('employeeId');
        $nameColumns = $find('employee');
        $conceptColumns = [];
        foreach ($mapping as $header => $field) {
            if (str_starts_with($field, 'c:')) {
                $conceptColumns[substr($field, 2)][] = $header;
            }
        }
        $rowFormat = $find('concept') !== [];

        $out = [];
        foreach ($rows as $row) {
            $base = [
                'employeeId' => $idColumns ? ($row[$idColumns[0]] ?? '') : '',
                'employee' => $nameColumns ? trim((string) ($row[$nameColumns[0]] ?? '')) : '',
            ];

            if ($rowFormat) {
                $item = $base;
                foreach (['concept', 'value', 'quantity'] as $field) {
                    if ($columns = $find($field)) {
                        $item[$field] = $row[$columns[0]] ?? '';
                    }
                }
                $out[] = $item;
            }

            foreach ($conceptColumns as $code => $headers) {
                $value = 0.0;
                foreach ($headers as $header) {
                    $value += self::toNumber($row[$header] ?? '');
                }
                $out[] = $base + ['concept' => self::CONCEPTS[$code]['label'], 'value' => round($value, 2), 'code' => $code];
            }
        }

        return $out;
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
