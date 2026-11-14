<?php

namespace App\Services\Audit;

use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Ods;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

/**
 * Lee archivos de nómina (Excel/CSV/ODS y PDF de planilla PILA) y devuelve encabezados y filas.
 *
 * Para hojas de cálculo:
 *  - Ubica la fila de encabezados (la que contiene la identificación del empleado), aunque
 *    arriba haya títulos como el nombre de la empresa o el periodo.
 *  - Completa encabezados de dos filas (p. ej. "NETO A PAGAR" escrito en la fila superior).
 *  - Ignora filas de totales, firmas y notas (filas sin identificación numérica).
 *  - Une todas las hojas que tengan detalle por empleado; las columnas de las hojas
 *    adicionales se nombran "Hoja · Encabezado".
 */
class SpreadsheetReader
{
    public const SUPPORTED = ['xlsx', 'xlsm', 'xls', 'csv', 'ods', 'txt', 'pdf'];

    private const SHEET_SEPARATOR = ' · ';

    public function __construct(private PilaPdfReader $pdfReader) {}

    public static function supports(string $extension): bool
    {
        return in_array(strtolower($extension), self::SUPPORTED, true);
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string,mixed>>, sheets: list<string>}
     */
    public function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        if ($extension === 'pdf') {
            return $this->pdfReader->read($path) + ['sheets' => ['Planilla PILA']];
        }

        $reader = $this->readerFor($extension);
        $reader->setReadDataOnly(true);
        if ($reader instanceof Csv) {
            $reader->setInputEncoding(Csv::guessEncoding($path, 'ISO-8859-1'));
            // Conserva el texto original ("200.000" no debe convertirse en 200).
            $reader->setValueBinder(new StringValueBinder);
        }

        $workbook = $reader->load($path);
        $tables = [];
        foreach ($workbook->getAllSheets() as $sheet) {
            $table = $this->extractTable($sheet->toArray(null, true, false, false));
            if ($table !== null) {
                $tables[] = ['name' => trim($sheet->getTitle(), " .\t"), ...$table];
            }
        }

        if ($tables === []) {
            // Sin columna de identificación reconocible: toma la primera hoja desde su primera fila con datos.
            $table = $this->extractTable($workbook->getSheet(0)->toArray(null, true, false, false), true);
            if ($table === null) {
                throw new RuntimeException('El archivo no contiene encabezados ni registros.');
            }
            $tables[] = ['name' => $workbook->getSheet(0)->getTitle(), ...$table];
        }

        return $this->merge($tables);
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string,mixed>>, idHeader: ?string, nameHeader: ?string}|null
     */
    private function extractTable(array $matrix, bool $fallback = false): ?array
    {
        $matrix = array_values($matrix);
        $headerIndex = null;
        $idColumn = null;

        foreach (array_slice($matrix, 0, 40, true) as $index => $row) {
            foreach ($row as $col => $cell) {
                if (ColumnMapper::guess((string) ($cell ?? '')) === 'employeeId') {
                    $headerIndex = $index;
                    $idColumn = $col;
                    break 2;
                }
            }
        }

        if ($headerIndex === null) {
            if (! $fallback) {
                return null;
            }
            foreach ($matrix as $index => $row) {
                if (! $this->isEmpty($row)) {
                    $headerIndex = $index;
                    break;
                }
            }
            if ($headerIndex === null) {
                return null;
            }
        }

        $dataRows = array_slice($matrix, $headerIndex + 1);
        if ($idColumn !== null) {
            // Solo filas con identificación (descarta totales, firmas y notas).
            $dataRows = array_values(array_filter($dataRows,
                fn ($row) => preg_match('/\d{3,}/', ColumnMapper::normalizeId($row[$idColumn] ?? ''))));
        } else {
            $dataRows = array_values(array_filter($dataRows, fn ($row) => ! $this->isEmpty($row)));
        }

        $headerRow = $matrix[$headerIndex];
        $upperRow = $matrix[$headerIndex - 1] ?? [];
        $width = max(count($headerRow), ...array_map('count', $dataRows ?: [[]]));

        $headers = [];
        $columns = [];
        $hasData = fn (int $col) => collect($dataRows)->contains(fn ($r) => trim((string) ($r[$col] ?? '')) !== '');

        for ($col = 0; $col < $width; $col++) {
            $name = trim((string) ($headerRow[$col] ?? ''));
            if ($name === '' && $hasData($col)) {
                // Encabezado escrito en la fila superior (celda combinada verticalmente).
                $name = trim((string) ($upperRow[$col] ?? '')) ?: 'Columna '.($col + 1);
            }
            if ($name === '') {
                continue; // Columna sin encabezado ni datos (celdas combinadas horizontalmente).
            }
            $name = preg_replace('/\s+/', ' ', $name);
            $base = $name;
            for ($n = 2; in_array($name, $headers, true); $n++) {
                $name = "$base ($n)";
            }
            $headers[] = $name;
            $columns[$col] = $name;
        }

        $rows = array_map(function ($row) use ($columns) {
            $assoc = [];
            foreach ($columns as $col => $header) {
                $assoc[$header] = $row[$col] ?? '';
            }

            return $assoc;
        }, $dataRows);

        $nameHeader = collect($headers)->first(fn ($h) => ColumnMapper::guess($h) === 'employee');

        return [
            'headers' => $headers,
            'rows' => $rows,
            'idHeader' => $idColumn !== null ? $columns[$idColumn] : null,
            'nameHeader' => $nameHeader,
        ];
    }

    /** Une las tablas de varias hojas por identificación del empleado. */
    private function merge(array $tables): array
    {
        $first = array_shift($tables);
        $headers = $first['headers'];
        $rows = $first['rows'];
        $sheets = [$first['name']];

        if ($first['idHeader'] === null) {
            return ['headers' => $headers, 'rows' => $rows, 'sheets' => $sheets];
        }

        $index = [];
        foreach ($rows as $i => $row) {
            $index[ColumnMapper::normalizeId($row[$first['idHeader']])] ??= $i;
        }

        foreach ($tables as $table) {
            if ($table['idHeader'] === null) {
                continue;
            }
            $sheets[] = $table['name'];
            $extra = array_values(array_filter($table['headers'],
                fn ($h) => $h !== $table['idHeader'] && $h !== $table['nameHeader']));
            $prefixed = [];
            foreach ($extra as $header) {
                $prefixed[$header] = $table['name'].self::SHEET_SEPARATOR.$header;
                $headers[] = $prefixed[$header];
            }

            foreach ($table['rows'] as $row) {
                $key = ColumnMapper::normalizeId($row[$table['idHeader']]);
                if (! isset($index[$key])) {
                    // Empleado que solo aparece en esta hoja.
                    $new = array_fill_keys($first['headers'], '');
                    $new[$first['idHeader']] = $row[$table['idHeader']];
                    if ($first['nameHeader'] && $table['nameHeader']) {
                        $new[$first['nameHeader']] = $row[$table['nameHeader']];
                    }
                    $rows[] = $new;
                    $index[$key] = array_key_last($rows);
                }
                foreach ($prefixed as $header => $name) {
                    $rows[$index[$key]][$name] = $row[$header] ?? '';
                }
            }
        }

        // Todas las filas con todas las columnas.
        $blank = array_fill_keys($headers, '');
        $rows = array_map(fn ($row) => array_merge($blank, $row), $rows);

        return ['headers' => $headers, 'rows' => $rows, 'sheets' => $sheets];
    }

    private function isEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function readerFor(string $extension): IReader
    {
        return match ($extension) {
            'xlsx', 'xlsm' => new Xlsx,
            'xls' => new Xls,
            'ods' => new Ods,
            'csv', 'txt' => new Csv,
            default => throw new RuntimeException("Formato .$extension no soportado para extracción automática."),
        };
    }
}
