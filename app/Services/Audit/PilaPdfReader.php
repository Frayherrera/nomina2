<?php

namespace App\Services\Audit;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extrae el detalle por afiliado de una planilla PILA en PDF (autoliquidación de aportes,
 * p. ej. SuAporte / Enlace Operativo). Usa pdftotext (poppler), que también abre los PDF
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
            $row = $this->parseLine($line);
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
        $process = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('No fue posible leer el PDF (pdftotext no disponible o archivo dañado).');
        }

        return $process->getOutput();
    }

    private function parseLine(string $line): ?array
    {
        $row = [];
        $money = fn (string $v) => (int) str_replace('.', '', $v);

        // Días AFP, EPS, ARL, CCF: cuatro enteros seguidos antes de la primera administradora.
        if (preg_match('/\s(\d{1,2})\s+(\d{1,2})\s+(\d{1,2})\s+(\d{1,2})\s+\(/', $line, $m)) {
            $row['Días cotizados'] = (int) max($m[1], $m[2], $m[3], $m[4]);
        }

        // Bloques "(CÓDIGO) ADMINISTRADORA $ IBC $ APORTE".
        preg_match_all('/\(([A-Z0-9-]+)\)\s+([^$(]+?)\s+'.self::MONEY.'\s+'.self::MONEY.'/u', $line, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $healthEnd = null;
        $cajaEnd = null;
        foreach ($blocks as $block) {
            $code = $block[1][0];
            $entity = trim(preg_replace('/\s+/', ' ', $block[2][0]));
            [$ibc, $contribution] = [$money($block[3][0]), $money($block[4][0])];

            if (str_starts_with($code, 'CCF')) {
                $row += ['Administradora caja' => $entity, 'IBC Caja' => $ibc, 'Aporte Caja' => $contribution];
                $cajaEnd = $block[0][1] + strlen($block[0][0]);
            } elseif (preg_match('/^(EPS|ESS|EAS|MIN|RES)/', $code)) {
                $row += ['Administradora salud' => $entity, 'IBC Salud' => $ibc, 'Aporte Salud' => $contribution];
                $healthEnd = $block[0][1] + strlen($block[0][0]);
            } else {
                $row += ['Administradora pensión' => $entity, 'IBC Pensión' => $ibc, 'Aporte Pensión' => $contribution];
            }
        }

        // Riesgos: "TARIFA $ IBC $ APORTE" (la tarifa lleva coma decimal, p. ej. 6,960).
        $rest = $healthEnd !== null ? substr($line, $healthEnd) : $line;
        if (preg_match('/\s(\d+,\d+)\s+'.self::MONEY.'(?:\s+'.self::MONEY.')?/', $rest, $m)) {
            $row['Tarifa ARL'] = $m[1];
            if (isset($m[3])) {
                $row['IBC Riesgos'] = $money($m[2]);
                $row['Aporte Riesgos'] = $money($m[3]);
            } else {
                $row['Aporte Riesgos'] = $money($m[2]);
            }
        }

        // Tras la caja: SENA, ICBF, ESAP, MinEducación y el total del afiliado (último valor).
        preg_match_all('/'.self::MONEY.'/', $line, $amounts);
        $values = array_map($money, $amounts[1]);
        if (count($values) < 3) {
            return null;
        }
        $row['Total aportes'] = end($values);
        if ($cajaEnd !== null && preg_match('/^\s*'.self::MONEY.'\s+'.self::MONEY.'/', substr($line, $cajaEnd), $m)) {
            $row['Aporte SENA'] = $money($m[1]);
            $row['Aporte ICBF'] = $money($m[2]);
        }

        return $row;
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

        $slice = fn (string $text) => trim(mb_substr($text, $start, $end - $start));
        $inline = $slice($line);
        if ($inline !== '') {
            return preg_replace('/\s+/', ' ', $inline);
        }

        $parts = [];
        foreach ([$i - 1, $i + 1] as $j) {
            $neighbor = $lines[$j] ?? '';
            if ($neighbor === '' || preg_match('/^\s*'.self::DOC.'\s+\d/u', $neighbor)) {
                continue;
            }
            $text = $slice($neighbor);
            if ($text !== '' && ! preg_match('/\d|\$/', $text)) {
                $parts[] = $text;
            }
        }

        return preg_replace('/\s+/', ' ', implode(' ', $parts));
    }
}
