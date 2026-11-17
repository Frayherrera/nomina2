<?php

namespace App\Services\Audit;

use RuntimeException;

/**
 * Extrae el detalle por afiliado de una planilla PILA en PDF (autoliquidación de aportes,
 * p. ej. SuAporte / Enlace Operativo). El texto se extrae en PHP puro (PdfTextExtractor), incluso de los PDF
 * protegidos con contraseña de propietario que generan los operadores.
 *
 * Cada afiliado ocupa una línea que empieza por tipo y número de documento:
 *   CC 1064836525  NOMBRE  01 00 ... 30 30 30 30 (230301) PORVENIR $ 1.750.905 $ 280.200 (EPS037) NUEVA EPS ...
 */
class PilaPdfReader
{
    public const HEADERS = [
        'Identificación', 'Nombre', 'Días cotizados',
        'Administradora pensión', 'IBC Pensión', 'Aporte Pensión',
        'Administradora salud', 'IBC Salud', 'Aporte Salud',
        'Tarifa ARL', 'IBC Riesgos', 'Aporte Riesgos',
        'Administradora caja', 'IBC Caja', 'Aporte Caja',
        'Aporte SENA', 'Aporte ICBF', 'Total aportes',
    ];

    private const DOC = '(?:CC|CE|TI|PA|PE|PT|CD|SC|RC)';

    private const MONEY = '\$\s*([\d.]+)';

    public function __construct(private ?PdfTextExtractor $extractor = null)
    {
        $this->extractor ??= new PdfTextExtractor(new PdfDecryptor);
    }

    /**
     * @return array{headers: list<string>, rows: list<array<string,mixed>>}
     */
    public function read(string $path): array
    {
        return $this->parse($this->extractText($path));
    }

    /**
     * Interpreta el texto de la planilla extraído con pdftotext -layout.
     *
     * @return array{headers: list<string>, rows: list<array<string,mixed>>}
     */
    public function parse(string $text): array
    {
        $lines = explode("\n", $text);
        $rows = [];

        foreach ($lines as $i => $line) {
            if (! preg_match('/^\s*'.self::DOC.'\s+(\d{4,})\s/u', $line, $doc) || ! str_contains($line, '$')) {
                continue;
            }
            $row = $this->parseLine($line, [$lines[$i - 1] ?? '', $lines[$i + 1] ?? '']);
            if ($row === null) {
                continue;
            }
            $row['Identificación'] = $doc[1];
            $row['Nombre'] = $this->name($lines, $i, $doc[0]);
            $rows[] = array_merge(array_fill_keys(self::HEADERS, ''), $row);
        }

        if ($rows === []) {
            throw new RuntimeException('No se encontró el detalle de afiliados de la planilla PILA en el PDF.');
        }

        return ['headers' => self::HEADERS, 'rows' => $rows];
    }

    private function extractText(string $path): string
    {
        return $this->extractor->extract($path);
    }

    /**
     * Interpreta el renglón del afiliado. El orden de los montos es fijo en la planilla:
     *   [días] PENSIÓN $IBC $aporte · SALUD $IBC $aporte · TARIFA [$IBC] $aporte riesgos ·
     *   CAJA $IBC $aporte · $SENA $ICBF $ESAP $MinEducación · $TOTAL
     * Los nombres de las administradoras pueden quedar partidos en los renglones vecinos.
     *
     * @param  array{0:string,1:string}  $neighbors
     */
    private function parseLine(string $line, array $neighbors = ['', '']): ?array
    {
        $row = [];
        $money = fn (string $v) => (int) str_replace('.', '', $v);

        // Días AFP, EPS, ARL, CCF: cuatro enteros seguidos antes de la primera administradora.
        $start = 0;
        if (preg_match('/\s(\d{1,2})\s+(\d{1,2})\s+(\d{1,2})\s+(\d{1,2})\s+(?=\(|[A-Z])/u', $line, $m, PREG_OFFSET_CAPTURE)) {
            $row['Días cotizados'] = (int) max($m[1][0], $m[2][0], $m[3][0], $m[4][0]);
            $start = $m[0][1] + strlen($m[0][0]);
        }
        $body = substr($line, $start);

        // Tarifa de riesgos (coma decimal, p. ej. 6,960) separa la parte de pensión/salud del resto.
        if (! preg_match('/\s(\d{1,2},\d{2,5})\s/', $body, $tariff, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $row['Tarifa ARL'] = $tariff[1][0];
        preg_match_all('/'.self::MONEY.'/', substr($body, 0, $tariff[0][1]), $before);
        preg_match_all('/'.self::MONEY.'/', substr($body, $tariff[0][1]), $after);
        $before = array_map($money, $before[1]);
        $after = array_map($money, $after[1]);
        if (count($after) < 3) {
            return null;
        }

        // Administradoras con su código entre paréntesis (en el renglón o en los vecinos).
        $entities = $this->entities($line.' '.implode(' ', $neighbors));
        $hasPension = isset($entities['pension']) || count($before) >= 4;
        $hasHealth = isset($entities['salud']) || count($before) >= 4;

        if ($hasPension && $hasHealth) {
            $row += ['IBC Pensión' => $before[0] ?? 0, 'Aporte Pensión' => $before[1] ?? 0, 'IBC Salud' => $before[2] ?? 0, 'Aporte Salud' => $before[3] ?? 0];
        } elseif ($hasPension) {
            $row += ['IBC Pensión' => $before[0] ?? 0, 'Aporte Pensión' => $before[1] ?? 0];
        } else {
            $row += ['IBC Salud' => $before[0] ?? 0, 'Aporte Salud' => $before[1] ?? 0];
        }
        $row['Administradora pensión'] = $entities['pension'] ?? '';
        $row['Administradora salud'] = $entities['salud'] ?? '';
        $row['Administradora caja'] = $entities['caja'] ?? '';

        // Desde el final: total, MinEducación, ESAP, ICBF, SENA, aporte caja, IBC caja; lo que queda es riesgos.
        $row['Total aportes'] = array_pop($after);
        array_pop($after); // Ministerio de Educación
        array_pop($after); // ESAP
        $row['Aporte ICBF'] = array_pop($after) ?? 0;
        $row['Aporte SENA'] = array_pop($after) ?? 0;
        $row['Aporte Caja'] = array_pop($after) ?? 0;
        $row['IBC Caja'] = array_pop($after) ?? 0;
        if (count($after) >= 2) {
            [$row['IBC Riesgos'], $row['Aporte Riesgos']] = [$after[0], $after[1]];
        } elseif (count($after) === 1) {
            $row['Aporte Riesgos'] = $after[0];
            $row['IBC Riesgos'] = $row['IBC Caja'] ?: ($row['IBC Salud'] ?? '');
        }

        return $row;
    }

    /** @return array<string,string> Administradoras de pensión, salud y caja por su código. */
    private function entities(string $text): array
    {
        $found = [];
        preg_match_all('/\(([A-Z0-9-]{3,})\)\s*([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ.\s]*?)(?=\s{2,}|\s*\$|\s*\(|\s*\d|$)/u', $text, $blocks, PREG_SET_ORDER);
        foreach ($blocks as $block) {
            $type = match (true) {
                str_starts_with($block[1], 'CCF') => 'caja',
                (bool) preg_match('/^(EPS|ESS|EAS|MIN|RES)/', $block[1]) => 'salud',
                (bool) preg_match('/^\d/', $block[1]) || str_starts_with($block[1], 'AFP') => 'pension',
                default => null,
            };
            if ($type && ! isset($found[$type])) {
                $found[$type] = trim(preg_replace('/\s+/', ' ', $block[2]));
            }
        }

        return $found;
    }

    /**
     * El nombre puede venir en la misma línea o partido en la línea anterior y la siguiente
     * (nombres largos). Se toma la zona de texto entre el documento y el tipo de cotizante.
     */
    private function name(array $lines, int $i, string $docPrefix): string
    {
        $line = $lines[$i];
        $start = mb_strlen(rtrim($docPrefix));
        $end = preg_match('/\s0\d\s+\d{2}\s/u', $line, $m, PREG_OFFSET_CAPTURE)
            ? mb_strlen(substr($line, 0, $m[0][1]))
            : $start + 32;

        $inline = trim(mb_substr($line, $start, $end - $start));
        if ($inline !== '') {
            return preg_replace('/\s+/', ' ', $inline);
        }

        // Nombre partido: fragmento que empieza dentro de la zona del nombre en el renglón
        // anterior o siguiente (hasta el siguiente bloque separado por dos o más espacios).
        $parts = [];
        foreach ([$i - 1, $i + 1] as $j) {
            $neighbor = $lines[$j] ?? '';
            if (trim($neighbor) === '' || preg_match('/^\s*'.self::DOC.'\s+\d/u', $neighbor)) {
                continue;
            }
            if (! preg_match('/\S/u', mb_substr($neighbor, $start), $first, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $column = $start + mb_strlen(substr(mb_substr($neighbor, $start), 0, $first[0][1]));
            if ($column >= $end) {
                continue;
            }
            $text = preg_split('/\s{2,}/u', trim(mb_substr($neighbor, $column)))[0];
            if ($text !== '' && ! preg_match('/\d|\$/', $text)) {
                $parts[] = $text;
            }
        }

        return preg_replace('/\s+/', ' ', implode(' ', $parts));
    }
}
