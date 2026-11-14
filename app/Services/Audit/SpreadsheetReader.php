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
 * Lee la primera hoja de un archivo Excel/CSV/ODS y devuelve encabezados y filas.
 */
class SpreadsheetReader
{
    public const SUPPORTED = ['xlsx', 'xlsm', 'xls', 'csv', 'ods', 'txt'];

    public static function supports(string $extension): bool
    {
        return in_array(strtolower($extension), self::SUPPORTED, true);
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string,mixed>>}
     */
    public function read(string $path, string $extension): array
    {
        $reader = $this->readerFor(strtolower($extension));
        $reader->setReadDataOnly(true);

        if ($reader instanceof Csv) {
            $reader->setInputEncoding(Csv::guessEncoding($path, 'ISO-8859-1'));
            // Conserva el texto original ("200.000" no debe convertirse en 200);
            // ColumnMapper::toNumber interpreta luego el formato colombiano.
            $reader->setValueBinder(new StringValueBinder);
        }

        $sheet = $reader->load($path)->getSheet(0);
        $matrix = $sheet->toArray(null, true, false, false);

        $isEmpty = fn (array $row) => collect($row)->every(fn ($cell) => trim((string) ($cell ?? '')) === '');

        $headerIndex = null;
        foreach ($matrix as $index => $row) {
            if (! $isEmpty($row)) {
                $headerIndex = $index;
                break;
            }
        }

        if ($headerIndex === null) {
            throw new RuntimeException('El archivo no contiene encabezados ni registros.');
        }

        // Encabezados únicos; columnas sin título reciben "Columna N".
        $headers = [];
        foreach ($matrix[$headerIndex] as $i => $cell) {
            $name = trim((string) ($cell ?? '')) ?: 'Columna '.($i + 1);
            $base = $name;
            $n = 2;
            while (in_array($name, $headers, true)) {
                $name = "$base ($n)";
                $n++;
            }
            $headers[] = $name;
        }

        // Elimina columnas finales vacías sin encabezado real.
        while ($headers && str_starts_with(end($headers), 'Columna ')
            && collect(array_slice($matrix, $headerIndex + 1))->every(fn ($r) => trim((string) ($r[count($headers) - 1] ?? '')) === '')) {
            array_pop($headers);
        }

        $rows = [];
        foreach (array_slice($matrix, $headerIndex + 1) as $row) {
            if ($isEmpty($row)) {
                continue;
            }
            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = $row[$i] ?? '';
            }
            $rows[] = $assoc;
        }

        return ['headers' => $headers, 'rows' => $rows];
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
