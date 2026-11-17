<?php

namespace App\Services\Audit;

use RuntimeException;

/**
 * Quita el cifrado "de propietario" de un PDF (el que permite abrirlo sin contraseña pero
 * restringe copiar o imprimir), como el de las planillas PILA de los operadores.
 *
 * Implementa el manejador estándar de seguridad de PDF (ISO 32000-1, algoritmos 1 y 2)
 * con contraseña de usuario vacía: RC4 de 40 a 128 bits y AES-128 (AESV2), en PHP puro,
 * y reescribe el archivo sin cifrado para poder extraer su texto.
 */
class PdfDecryptor
{
    private const PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        ."\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private string $key = '';

    private bool $aes = false;

    public static function isEncrypted(string $pdf): bool
    {
        return (bool) preg_match('#/Encrypt\s+\d+\s+\d+\s+R#', $pdf);
    }

    /** Devuelve el PDF descifrado (o el mismo contenido si no está cifrado). */
    public function decrypt(string $pdf): string
    {
        if (! preg_match_all('#/Encrypt\s+(\d+)\s+(\d+)\s+R#', $pdf, $m)) {
            return $pdf;
        }
        $encryptNum = (int) end($m[1]);

        $objects = self::parseObjects($pdf);
        if (! isset($objects[$encryptNum])) {
            throw new RuntimeException('No se encontró el diccionario de cifrado del PDF.');
        }

        $this->deriveKey($objects[$encryptNum]['dict'], $this->documentId($pdf));

        $out = [];
        foreach ($objects as $num => $object) {
            if ($num === $encryptNum || preg_match('#/Type\s*/XRef\b#', $object['dict'])) {
                continue;
            }
            $objectKey = $this->objectKey($num, $object['gen']);
            $dict = $this->decryptStrings($object['dict'], $objectKey);

            if ($object['stream'] !== null) {
                $data = $this->cipher($objectKey, $object['stream']);
                $dict = $this->setLength($dict, strlen($data));
                $out[$num] = ['gen' => $object['gen'], 'body' => $dict."\nstream\n".$data."\nendstream"];
            } else {
                $out[$num] = ['gen' => $object['gen'], 'body' => $dict];
            }
        }

        return $this->write($out, $pdf);
    }

    /**
     * Separa los objetos "N G obj ... endobj" (el último gana en actualizaciones incrementales).
     *
     * @return array<int, array{gen:int, dict:string, stream:?string}>
     */
    public static function parseObjects(string $pdf): array
    {
        preg_match_all('#(?<![\d])(\d+)\s+(\d+)\s+obj\b#', $pdf, $matches, PREG_OFFSET_CAPTURE);
        $raw = [];
        foreach ($matches[0] as $i => $match) {
            $raw[] = ['num' => (int) $matches[1][$i][0], 'gen' => (int) $matches[2][$i][0], 'start' => $match[1] + strlen($match[0])];
        }

        $objects = [];
        $lengths = [];
        // Primera pasada: objetos sin flujo (para resolver longitudes indirectas).
        foreach ($raw as $item) {
            $end = strpos($pdf, 'endobj', $item['start']);
            $body = substr($pdf, $item['start'], $end === false ? null : $end - $item['start']);
            if (preg_match('#^\s*(\d+)\s*$#', $body, $n)) {
                $lengths[$item['num']] = (int) $n[1];
            }
        }

        foreach ($raw as $item) {
            $streamPos = self::findStreamKeyword($pdf, $item['start']);
            $endobj = strpos($pdf, 'endobj', $item['start']);

            if ($streamPos !== null && ($endobj === false || $streamPos < $endobj)) {
                $dict = substr($pdf, $item['start'], $streamPos - $item['start']);
                $dataStart = $streamPos + 6;
                if (($pdf[$dataStart] ?? '') === "\r") {
                    $dataStart++;
                }
                if (($pdf[$dataStart] ?? '') === "\n") {
                    $dataStart++;
                }
                $length = null;
                if (preg_match('#/Length\s+(\d+)\s+(\d+)\s+R#', $dict, $l)) {
                    $length = $lengths[(int) $l[1]] ?? null;
                } elseif (preg_match('#/Length\s+(\d+)#', $dict, $l)) {
                    $length = (int) $l[1];
                }
                if ($length === null) {
                    $length = strpos($pdf, 'endstream', $dataStart) - $dataStart;
                }
                $objects[$item['num']] = ['gen' => $item['gen'], 'dict' => trim($dict), 'stream' => substr($pdf, $dataStart, $length)];
            } else {
                $body = substr($pdf, $item['start'], $endobj === false ? null : $endobj - $item['start']);
                $objects[$item['num']] = ['gen' => $item['gen'], 'dict' => trim($body), 'stream' => null];
            }
        }

        return $objects;
    }

    /** Ubica la palabra "stream" que sigue al diccionario del objeto (fuera de cadenas). */
    private static function findStreamKeyword(string $pdf, int $from): ?int
    {
        $depth = 0;
        $len = strlen($pdf);
        for ($i = $from; $i < $len; $i++) {
            $c = $pdf[$i];
            if ($c === '(') {
                $i = self::skipLiteral($pdf, $i);
            } elseif ($c === '<' && ($pdf[$i + 1] ?? '') === '<') {
                $depth++;
                $i++;
            } elseif ($c === '>' && ($pdf[$i + 1] ?? '') === '>') {
                $depth--;
                $i++;
            } elseif ($depth <= 0 && substr_compare($pdf, 'endobj', $i, 6) === 0) {
                return null;
            } elseif ($depth <= 0 && substr_compare($pdf, 'stream', $i, 6) === 0) {
                return $i;
            }
        }

        return null;
    }

    public static function skipLiteral(string $s, int $i): int
    {
        $depth = 0;
        $len = strlen($s);
        for (; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\') {
                $i++;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                if (--$depth === 0) {
                    return $i;
                }
            }
        }

        return $len;
    }

    private function documentId(string $pdf): string
    {
        if (preg_match('#/ID\s*\[\s*<([0-9A-Fa-f\s]*)>#', $pdf, $m)) {
            return hex2bin(preg_replace('/\s+/', '', $m[1]));
        }
        if (preg_match('#/ID\s*\[\s*\(#', $pdf, $m, PREG_OFFSET_CAPTURE)) {
            $start = strpos($pdf, '(', $m[0][1]);

            return self::unescape(substr($pdf, $start + 1, self::skipLiteral($pdf, $start) - $start - 1));
        }

        return '';
    }

    /** Algoritmo 2: clave de cifrado con contraseña de usuario vacía. */
    private function deriveKey(string $dict, string $id): void
    {
        $int = fn (string $name, int $default) => preg_match('#/'.$name.'\s+(-?\d+)#', $dict, $m) ? (int) $m[1] : $default;
        $v = $int('V', 0);
        $r = $int('R', 2);

        if (! preg_match('#/Filter\s*/Standard#', $dict) || $v >= 5 || $r >= 5) {
            throw new RuntimeException('El PDF usa un tipo de cifrado no soportado (AES-256). Imprímelo como PDF o expórtalo a Excel.');
        }

        $this->aes = $v === 4 && str_contains($dict, '/AESV2');
        $length = $v === 1 ? 5 : intdiv($int('Length', $v === 4 ? 128 : 40), 8);
        $owner = $this->stringValue($dict, 'O');
        $permissions = pack('V', $int('P', 0) & 0xFFFFFFFF);

        $hash = md5(self::PADDING.substr($owner, 0, 32).$permissions.$id
            .($r >= 4 && preg_match('#/EncryptMetadata\s+false#', $dict) ? "\xFF\xFF\xFF\xFF" : ''), true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $hash = md5(substr($hash, 0, $length), true);
            }
        }
        $this->key = substr($hash, 0, $length);
    }

    private function stringValue(string $dict, string $name): string
    {
        if (! preg_match('#/'.$name.'\s*([(<])#', $dict, $m, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("Falta /$name en el diccionario de cifrado del PDF.");
        }
        $start = $m[1][1];
        if ($m[1][0] === '<') {
            return hex2bin(preg_replace('/[^0-9A-Fa-f]/', '', substr($dict, $start + 1, strpos($dict, '>', $start) - $start - 1)));
        }

        return self::unescape(substr($dict, $start + 1, self::skipLiteral($dict, $start) - $start - 1));
    }

    /** Algoritmo 1: clave por objeto. */
    private function objectKey(int $num, int $gen): string
    {
        $seed = $this->key.substr(pack('V', $num), 0, 3).substr(pack('V', $gen), 0, 2).($this->aes ? 'sAlT' : '');

        return substr(md5($seed, true), 0, min(strlen($this->key) + 5, 16));
    }

    private function cipher(string $key, string $data): string
    {
        if (! $this->aes) {
            return self::rc4($key, $data);
        }
        if (strlen($data) < 32) {
            return '';
        }
        $plain = openssl_decrypt(substr($data, 16), 'aes-128-cbc', $key, OPENSSL_RAW_DATA, substr($data, 0, 16));

        return $plain === false ? '' : $plain;
    }

    public static function rc4(string $key, string $data): string
    {
        $s = range(0, 255);
        $keyLength = strlen($key);
        for ($i = 0, $j = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $keyLength])) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }

        $out = '';
        $length = strlen($data);
        for ($i = 0, $j = 0, $n = 0; $n < $length; $n++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $s[$i]) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$n]) ^ $s[($s[$i] + $s[$j]) & 0xFF]);
        }

        return $out;
    }

    /** Descifra las cadenas literales y hexadecimales del diccionario y las escribe en hexadecimal. */
    private function decryptStrings(string $dict, string $key): string
    {
        $out = '';
        $len = strlen($dict);
        for ($i = 0; $i < $len; $i++) {
            $c = $dict[$i];
            if ($c === '(') {
                $end = self::skipLiteral($dict, $i);
                $out .= '<'.bin2hex($this->cipher($key, self::unescape(substr($dict, $i + 1, $end - $i - 1)))).'>';
                $i = $end;
            } elseif ($c === '<' && ($dict[$i + 1] ?? '') !== '<') {
                $end = strpos($dict, '>', $i);
                $bytes = hex2bin(str_pad(preg_replace('/[^0-9A-Fa-f]/', '', substr($dict, $i + 1, $end - $i - 1)), 2, '0'));
                $out .= '<'.bin2hex($this->cipher($key, (string) $bytes)).'>';
                $i = $end;
            } elseif ($c === '<') {
                $out .= '<<';
                $i++;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    public static function unescape(string $s): string
    {
        return preg_replace_callback('/\\\\(?:([0-7]{1,3})|(\r\n|\r|\n)|(.))/s', function ($m) {
            if (($m[1] ?? '') !== '') {
                return chr(octdec($m[1]) & 0xFF);
            }
            if (($m[2] ?? '') !== '') {
                return '';
            }

            return match ($m[3]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                default => $m[3],
            };
        }, $s);
    }

    private function setLength(string $dict, int $length): string
    {
        $dict = preg_replace('#/Length\s+\d+\s+\d+\s+R#', '/Length '.$length, $dict, 1, $count);
        if (! $count) {
            $dict = preg_replace('#/Length\s+\d+#', '/Length '.$length, $dict, 1, $count);
        }

        return $count ? $dict : preg_replace('#^<<#', '<</Length '.$length.' ', $dict, 1);
    }

    /** Escribe un PDF nuevo con tabla de referencias clásica y sin /Encrypt. */
    private function write(array $objects, string $original): string
    {
        ksort($objects);
        $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $object) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "$num {$object['gen']} obj\n{$object['body']}\nendobj\n";
        }

        $size = max(array_keys($objects)) + 1;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 $size\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $pdf .= isset($offsets[$i])
                ? sprintf("%010d %05d n \n", $offsets[$i], $objects[$i]['gen'])
                : "0000000000 65535 f \n";
        }

        $trailer = '/Size '.$size;
        foreach (['Root', 'Info'] as $name) {
            if (preg_match_all('#/'.$name.'\s+(\d+\s+\d+\s+R)#', $original, $m)) {
                $trailer .= " /$name ".end($m[1]);
            }
        }

        return $pdf."trailer\n<<$trailer>>\nstartxref\n$xref\n%%EOF\n";
    }
}
