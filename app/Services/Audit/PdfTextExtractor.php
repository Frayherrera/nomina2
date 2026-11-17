<?php

namespace App\Services\Audit;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Extrae el texto de un PDF conservando la disposición en columnas (una línea de texto por
 * renglón de la tabla), en PHP puro: no requiere programas instalados en el servidor.
 *
 *  - Quita el cifrado de propietario (PdfDecryptor).
 *  - Lee los objetos del PDF (incluidos los comprimidos en flujos de objetos).
 *  - Interpreta los operadores de texto de cada página (BT/ET, Tm, Td, TD, T*, Tf, Tj, TJ, ', ")
 *    con la matriz de transformación (q/Q/cm) y la rotación de la página.
 *  - Decodifica el texto con el mapa ToUnicode de la fuente o, si no tiene, con WinAnsi.
 *
 * Si falla y el servidor tiene pdftotext (poppler), se usa como respaldo.
 */
class PdfTextExtractor
{
    /** Puntos de página por carácter al reconstruir las columnas. */
    private const UNIT = 2.0;

    /** Diferencia vertical máxima (puntos) para considerar dos fragmentos en el mismo renglón. */
    private const LINE_TOLERANCE = 1.2;

    /** @var array<int, array{gen:int, dict:string, stream:?string}> */
    private array $objects = [];

    public function __construct(private PdfDecryptor $decryptor) {}

    public function extract(string $path): string
    {
        try {
            return $this->extractWithPhp($path);
        } catch (Throwable $e) {
            $text = $this->extractWithPdftotext($path);
            if ($text !== null) {
                return $text;
            }
            throw new RuntimeException('No fue posible leer el PDF: '.$e->getMessage(), 0, $e);
        }
    }

    public function extractWithPhp(string $path): string
    {
        $content = @file_get_contents($path);
        if ($content === false || ! str_contains(substr($content, 0, 1024), '%PDF')) {
            throw new RuntimeException('el archivo no es un PDF válido.');
        }
        if (PdfDecryptor::isEncrypted($content)) {
            $content = $this->decryptor->decrypt($content);
        }

        $this->objects = PdfDecryptor::parseObjects($content);
        $this->expandObjectStreams();

        $pages = [];
        foreach ($this->pages() as $page) {
            $pages[] = $this->pageText($page);
        }

        $text = implode("\n\f", $pages);
        if (trim($text) === '') {
            throw new RuntimeException('el PDF no contiene texto (puede ser una imagen escaneada).');
        }

        return $text;
    }

    // ------------------------------------------------------------------ Objetos

    private function expandObjectStreams(): void
    {
        foreach ($this->objects as $object) {
            if ($object['stream'] === null || ! preg_match('#/Type\s*/ObjStm\b#', $object['dict'])) {
                continue;
            }
            $data = $this->decodeStream($object);
            $first = (int) $this->number($object['dict'], 'First');
            $count = (int) $this->number($object['dict'], 'N');
            preg_match_all('/\d+/', substr($data, 0, $first), $pairs);
            for ($i = 0; $i < $count; $i++) {
                $num = (int) ($pairs[0][$i * 2] ?? 0);
                $offset = (int) ($pairs[0][$i * 2 + 1] ?? 0);
                $next = isset($pairs[0][$i * 2 + 3]) ? (int) $pairs[0][$i * 2 + 3] : strlen($data) - $first;
                $this->objects[$num] ??= ['gen' => 0, 'dict' => trim(substr($data, $first + $offset, $next - $offset)), 'stream' => null];
            }
        }
    }

    private function decodeStream(array $object): string
    {
        $data = (string) $object['stream'];
        if (preg_match('#/Filter\s*(\[[^\]]*\]|/\w+)#', $object['dict'], $m)) {
            preg_match_all('#/(\w+)#', $m[1], $filters);
            foreach ($filters[1] as $filter) {
                $data = match ($filter) {
                    'FlateDecode', 'Fl' => @gzuncompress($data) ?: @gzinflate(substr($data, 2)) ?: @gzinflate($data) ?: '',
                    default => throw new RuntimeException("filtro $filter no soportado"),
                };
            }
        }

        return $data;
    }

    /** Resuelve "N G R" al contenido del objeto; devuelve el mismo texto si es directo. */
    private function resolve(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^(\d+)\s+\d+\s+R$#', $value, $m)) {
            return $this->objects[(int) $m[1]]['dict'] ?? '';
        }

        return $value;
    }

    private function number(string $dict, string $key): ?float
    {
        return preg_match('#/'.$key.'\s+(-?[\d.]+)(?!\s+\d+\s+R)#', $dict, $m) ? (float) $m[1] : null;
    }

    /** Valor de una clave del diccionario (objeto directo o referencia). */
    private function entry(string $dict, string $key): ?string
    {
        if (! preg_match('#/'.$key.'(?![A-Za-z0-9])\s*#', $dict, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $rest = substr($dict, $start);

        if (preg_match('#^(\d+\s+\d+\s+R)#', $rest, $r)) {
            return $r[1];
        }
        if (str_starts_with($rest, '<<')) {
            return $this->balanced($rest, '<<', '>>');
        }
        if (str_starts_with($rest, '[')) {
            return $this->balanced($rest, '[', ']');
        }
        preg_match('#^(/?[^\s/<>\[\]()]+)#', $rest, $v);

        return $v[1] ?? null;
    }

    private function balanced(string $s, string $open, string $close): string
    {
        $depth = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '(') {
                $i = PdfDecryptor::skipLiteral($s, $i);
            } elseif (substr_compare($s, $open, $i, strlen($open)) === 0) {
                $depth++;
                $i += strlen($open) - 1;
            } elseif (substr_compare($s, $close, $i, strlen($close)) === 0) {
                $depth--;
                $i += strlen($close) - 1;
                if ($depth === 0) {
                    return substr($s, 0, $i + 1);
                }
            }
        }

        return $s;
    }

    /** @return list<array{dict:string, inherited:array<string,string>}> */
    private function pages(): array
    {
        $pages = [];
        $walk = function (int $num, array $inherited, int $depth) use (&$walk, &$pages) {
            $dict = $this->objects[$num]['dict'] ?? '';
            if ($depth > 50 || $dict === '') {
                return;
            }
            foreach (['Resources', 'MediaBox', 'Rotate'] as $key) {
                if (($value = $this->entry($dict, $key)) !== null) {
                    $inherited[$key] = $value;
                }
            }
            if (preg_match('#/Type\s*/Pages\b#', $dict)) {
                preg_match_all('#(\d+)\s+\d+\s+R#', (string) $this->resolve((string) $this->entry($dict, 'Kids')), $kids);
                foreach ($kids[1] as $kid) {
                    $walk((int) $kid, $inherited, $depth + 1);
                }
            } elseif (preg_match('#/Type\s*/Page\b#', $dict)) {
                $pages[] = ['dict' => $dict, 'inherited' => $inherited];
            }
        };

        foreach ($this->objects as $num => $object) {
            if (preg_match('#/Type\s*/Pages\b#', $object['dict']) && ! str_contains($object['dict'], '/Parent')) {
                $walk($num, [], 0);
            }
        }

        if ($pages === []) {
            foreach ($this->objects as $object) {
                if (preg_match('#/Type\s*/Page\b#', $object['dict'])) {
                    $pages[] = ['dict' => $object['dict'], 'inherited' => []];
                }
            }
        }

        return $pages;
    }

    // ------------------------------------------------------------------ Página

    private function pageText(array $page): string
    {
        $content = '';
        $contents = (string) $this->entry($page['dict'], 'Contents');
        // Una referencia a un flujo, o un arreglo de referencias (directo o indirecto).
        if (preg_match('#^(\d+)\s+\d+\s+R$#', $contents, $single) && ($this->objects[(int) $single[1]]['stream'] ?? null) === null) {
            $contents = $this->resolve($contents);
        }
        preg_match_all('#(\d+)\s+\d+\s+R#', $contents, $refs);
        foreach ($refs[1] as $ref) {
            if (isset($this->objects[(int) $ref]) && $this->objects[(int) $ref]['stream'] !== null) {
                $content .= $this->decodeStream($this->objects[(int) $ref])."\n";
            }
        }

        $fonts = $this->fonts($this->resolve($page['inherited']['Resources'] ?? ''));
        preg_match_all('/-?[\d.]+/', $this->resolve($page['inherited']['MediaBox'] ?? '[0 0 612 792]'), $box);
        $width = (float) (($box[0][2] ?? 612) - ($box[0][0] ?? 0));
        $height = (float) (($box[0][3] ?? 792) - ($box[0][1] ?? 0));
        $rotate = ((int) $this->resolve($page['inherited']['Rotate'] ?? '0') % 360 + 360) % 360;

        $fragments = [];
        foreach ($this->interpret($content, $fonts) as [$x, $y, $text]) {
            // Coordenadas como se ve la página (aplicando /Rotate).
            [$vx, $vy] = match ($rotate) {
                90 => [$y, $width - $x],
                180 => [$width - $x, $height - $y],
                270 => [$height - $y, $x],
                default => [$x, $y],
            };
            $fragments[] = ['x' => $vx, 'y' => $vy, 'text' => $text];
        }

        return $this->layout($fragments);
    }

    /** @return array<string, array{map: array<string,string>, bytes:int}> */
    private function fonts(string $resources): array
    {
        $fonts = [];
        $fontDict = $this->resolve((string) $this->entry($resources, 'Font'));
        preg_match_all('#/([^\s/<>\[\]()]+)\s+(\d+\s+\d+\s+R|<<)#', substr($fontDict, 2, -2), $m, PREG_OFFSET_CAPTURE);
        foreach ($m[1] as $i => $name) {
            $value = $m[2][$i][0] === '<<'
                ? $this->balanced(substr($fontDict, 2 + $m[2][$i][1]), '<<', '>>')
                : $this->resolve($m[2][$i][0]);
            $map = [];
            $bytes = str_contains($value, '/Type0') ? 2 : 1;
            $toUnicode = $this->entry($value, 'ToUnicode');
            if ($toUnicode && preg_match('#^(\d+)#', $toUnicode, $ref) && isset($this->objects[(int) $ref[1]])) {
                [$map, $bytes] = $this->parseCMap($this->decodeStream($this->objects[(int) $ref[1]]), $bytes);
            }
            $fonts[$name[0]] = ['map' => $map, 'bytes' => $bytes];
        }

        return $fonts;
    }

    /** @return array{0: array<string,string>, 1: int} */
    private function parseCMap(string $cmap, int $bytes): array
    {
        $map = [];
        $hexToUtf8 = fn (string $hex) => mb_convert_encoding(hex2bin(str_pad($hex, (int) ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT)), 'UTF-8', 'UTF-16BE');

        if (preg_match('#begincodespacerange\s*<([0-9A-Fa-f]+)>#', $cmap, $cs)) {
            $bytes = intdiv(strlen($cs[1]), 2);
        }
        preg_match_all('#beginbfchar(.*?)endbfchar#s', $cmap, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>#', $block, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $p) {
                $map[strtolower($p[1])] = $hexToUtf8($p[2]);
            }
        }
        preg_match_all('#beginbfrange(.*?)endbfrange#s', $cmap, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])#', $block, $ranges, PREG_SET_ORDER);
            foreach ($ranges as $r) {
                $from = hexdec($r[1]);
                $to = min(hexdec($r[2]), $from + 65535);
                $width = strlen($r[1]);
                if ($r[3][0] === '[') {
                    preg_match_all('#<([0-9A-Fa-f]+)>#', $r[3], $dst);
                    foreach ($dst[1] as $k => $hex) {
                        $map[str_pad(dechex($from + $k), $width, '0', STR_PAD_LEFT)] = $hexToUtf8($hex);
                    }
                } else {
                    $base = hexdec(trim($r[3], '<>'));
                    for ($code = $from; $code <= $to; $code++) {
                        $map[str_pad(dechex($code), $width, '0', STR_PAD_LEFT)] = $hexToUtf8(dechex($base + $code - $from));
                    }
                }
            }
        }

        return [$map, $bytes];
    }

    private function decodeText(string $bytes, ?array $font): string
    {
        if ($font && $font['map']) {
            $out = '';
            $step = max(1, $font['bytes']);
            for ($i = 0; $i < strlen($bytes); $i += $step) {
                $code = bin2hex(substr($bytes, $i, $step));
                $out .= $font['map'][$code] ?? '';
            }

            return $out;
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /**
     * Interpreta el flujo de contenido y devuelve [x, y, texto] en coordenadas de página.
     *
     * @return list<array{0: float, 1: float, 2: string}>
     */
    private function interpret(string $content, array $fonts): array
    {
        $identity = [1, 0, 0, 1, 0, 0];
        $ctm = $identity;
        $stack = [];
        $tm = $tlm = $identity;
        $font = null;
        $size = 10.0;
        $leading = 0.0;
        $operands = [];
        $out = [];

        $emit = function (string $raw) use (&$tm, &$ctm, &$font, &$size, &$out) {
            $text = $this->decodeText($raw, $font);
            if ($text === '') {
                return;
            }
            $m = $this->multiply($tm, $ctm);
            $out[] = [$m[4], $m[5], $text];
            // Avance aproximado (las tablas de PILA posicionan cada celda con Tm).
            $tm = $this->multiply([1, 0, 0, 1, mb_strlen($text) * $size * 0.5, 0], $tm);
        };

        $len = strlen($content);
        for ($i = 0; $i < $len; $i++) {
            $c = $content[$i];

            if (ctype_space($c)) {
                continue;
            }
            if ($c === '%') {
                $i = strpos($content, "\n", $i) ?: $len;

                continue;
            }
            if ($c === '(') {
                $end = PdfDecryptor::skipLiteral($content, $i);
                $operands[] = ['str', PdfDecryptor::unescape(substr($content, $i + 1, $end - $i - 1))];
                $i = $end;

                continue;
            }
            if ($c === '<' && ($content[$i + 1] ?? '') !== '<') {
                $end = strpos($content, '>', $i);
                $operands[] = ['str', (string) hex2bin(str_pad(preg_replace('/[^0-9A-Fa-f]/', '', substr($content, $i + 1, $end - $i - 1)), 2, '0'))];
                $i = $end;

                continue;
            }
            if ($c === '[') {
                $operands[] = ['arr_start'];

                continue;
            }
            if ($c === ']') {
                $items = [];
                while ($operands && ($last = array_pop($operands))[0] !== 'arr_start') {
                    array_unshift($items, $last);
                }
                $operands[] = ['arr', $items];

                continue;
            }
            if ($c === '<' || $c === '>') {
                // Diccionarios en línea (p. ej. BDC): se ignoran.
                $i++;

                continue;
            }

            preg_match('#\G(/[^\s/<>\[\]()%]*|[^\s/<>\[\]()%]+)#', $content, $tok, 0, $i);
            $token = $tok[1] ?? $c;
            $i += strlen($token) - 1;

            if ($token[0] === '/') {
                $operands[] = ['name', substr($token, 1)];

                continue;
            }
            if (is_numeric($token)) {
                $operands[] = ['num', (float) $token];

                continue;
            }

            $nums = array_values(array_map(fn ($o) => $o[1], array_filter($operands, fn ($o) => $o[0] === 'num')));
            switch ($token) {
                case 'q':
                    $stack[] = $ctm;
                    break;
                case 'Q':
                    $ctm = array_pop($stack) ?? $identity;
                    break;
                case 'cm':
                    if (count($nums) >= 6) {
                        $ctm = $this->multiply(array_slice($nums, -6), $ctm);
                    }
                    break;
                case 'BT':
                    $tm = $tlm = $identity;
                    break;
                case 'Tm':
                    if (count($nums) >= 6) {
                        $tm = $tlm = array_slice($nums, -6);
                    }
                    break;
                case 'Td':
                case 'TD':
                    if (count($nums) >= 2) {
                        [$tx, $ty] = array_slice($nums, -2);
                        if ($token === 'TD') {
                            $leading = -$ty;
                        }
                        $tm = $tlm = $this->multiply([1, 0, 0, 1, $tx, $ty], $tlm);
                    }
                    break;
                case 'TL':
                    $leading = (float) (end($nums) ?: 0);
                    break;
                case 'T*':
                    $tm = $tlm = $this->multiply([1, 0, 0, 1, 0, -$leading], $tlm);
                    break;
                case 'Tf':
                    $name = collect($operands)->last(fn ($o) => $o[0] === 'name');
                    $font = $name ? ($fonts[$name[1]] ?? null) : null;
                    $size = (float) (end($nums) ?: $size);
                    break;
                case 'Tj':
                case "'":
                case '"':
                    if ($token !== 'Tj') {
                        $tm = $tlm = $this->multiply([1, 0, 0, 1, 0, -$leading], $tlm);
                    }
                    $string = collect($operands)->last(fn ($o) => $o[0] === 'str');
                    if ($string) {
                        $emit($string[1]);
                    }
                    break;
                case 'TJ':
                    $array = collect($operands)->last(fn ($o) => $o[0] === 'arr');
                    if ($array) {
                        $raw = '';
                        foreach ($array[1] as $item) {
                            if ($item[0] === 'str') {
                                $raw .= $item[1];
                            } elseif ($item[0] === 'num' && $item[1] < -250) {
                                $raw .= ' ';
                            }
                        }
                        $emit($raw);
                    }
                    break;
            }
            $operands = [];
        }

        return $out;
    }

    /** Producto de matrices PDF [a b c d e f]: m1 × m2. */
    private function multiply(array $m1, array $m2): array
    {
        return [
            $m1[0] * $m2[0] + $m1[1] * $m2[2],
            $m1[0] * $m2[1] + $m1[1] * $m2[3],
            $m1[2] * $m2[0] + $m1[3] * $m2[2],
            $m1[2] * $m2[1] + $m1[3] * $m2[3],
            $m1[4] * $m2[0] + $m1[5] * $m2[2] + $m2[4],
            $m1[4] * $m2[1] + $m1[5] * $m2[3] + $m2[5],
        ];
    }

    /** Arma renglones de texto ubicando cada fragmento en su columna. */
    private function layout(array $fragments): string
    {
        usort($fragments, fn ($a, $b) => $b['y'] <=> $a['y'] ?: $a['x'] <=> $b['x']);

        $lines = [];
        foreach ($fragments as $fragment) {
            $last = array_key_last($lines);
            if ($last !== null && abs($lines[$last]['y'] - $fragment['y']) <= self::LINE_TOLERANCE) {
                $lines[$last]['items'][] = $fragment;
            } else {
                $lines[] = ['y' => $fragment['y'], 'items' => [$fragment]];
            }
        }

        $output = [];
        foreach ($lines as $line) {
            usort($line['items'], fn ($a, $b) => $a['x'] <=> $b['x']);
            $text = '';
            foreach ($line['items'] as $item) {
                $column = max(0, (int) round($item['x'] / self::UNIT));
                $current = mb_strlen($text);
                if ($column > $current) {
                    $text .= str_repeat(' ', $column - $current);
                } elseif ($current > 0 && ! str_ends_with($text, ' ')) {
                    $text .= ' ';
                }
                $text .= $item['text'];
            }
            $output[] = rtrim($text);
        }

        return implode("\n", $output);
    }

    private function extractWithPdftotext(string $path): ?string
    {
        try {
            $binary = (new ExecutableFinder)->find('pdftotext');
            if ($binary === null) {
                return null;
            }
            $process = new Process([$binary, '-layout', '-enc', 'UTF-8', $path, '-']);
            $process->setTimeout(60);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput() : null;
        } catch (Throwable) {
            return null; // proc_open deshabilitado en el hosting, etc.
        }
    }
}
