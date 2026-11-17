<?php

namespace Tests\Unit;

use App\Services\Audit\PdfDecryptor;
use App\Services\Audit\PdfTextExtractor;
use App\Services\Audit\PilaPdfReader;
use PHPUnit\Framework\TestCase;

/**
 * Lectura de PDF en PHP puro (sin pdftotext): página rotada 90° como la planilla PILA,
 * protegida con cifrado RC4 de 128 bits de propietario. Datos sintéticos.
 */
class PdfTextExtractorTest extends TestCase
{
    private const PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        ."\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private function text(float $x, float $y, string $text): string
    {
        return "BT\n1 0 0 1 $x $y Tm\n/F1 4 Tf\n(".addcslashes($text, '()\\').")Tj\nET\n";
    }

    private function planillaPdf(bool $encrypt): string
    {
        $rowY = 300;
        $cells = [
            [11, $rowY, 'CC 1001'], [34, $rowY + 2.5, 'ANA MARIA'], [34, $rowY - 2.5, 'GOMEZ'],
            [80, $rowY, '01'], [100, $rowY, '00'],
            [170, $rowY, '0'], [176, $rowY, '30'], [182, $rowY, '30'], [188, $rowY, '30'], [194, $rowY, '30'],
            [200, $rowY, '(230301) PORVENIR'], [245, $rowY, '$ 1.750.905'], [275, $rowY, '$ 280.200'],
            [300, $rowY + 2.5, '(ESSC07)'], [300, $rowY - 2.5, 'MUTUAL SER'],
            [345, $rowY, '$ 1.750.905'], [375, $rowY, '$ 70.100'], [405, $rowY, '6,960'],
            [425, $rowY, '$ 1.750.905'], [455, $rowY, '$ 121.900'], [480, $rowY, '(CCF16) COMFACOR'],
            [530, $rowY, '$ 1.750.905'], [560, $rowY, '$ 70.100'], [590, $rowY, '$ 0'], [610, $rowY, '$ 0'],
            [630, $rowY, '$ 0'], [650, $rowY, '$ 0'], [700, $rowY, '$ 542.300'],
        ];
        // Página vertical (612 x 846) con /Rotate 90 y matriz que dibuja en horizontal, como la PILA.
        $content = "0 1 -1 0 612 0 cm\n".implode('', array_map(fn ($c) => $this->text(...$c), $cells));
        $stream = gzcompress($content);

        $objects = [
            1 => '<</Type/Catalog/Pages 2 0 R>>',
            2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
            3 => '<</Type/Page/Parent 2 0 R/Rotate 90/MediaBox[0 0 612 846]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>',
            4 => null,
            5 => '<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>',
        ];

        $id = str_repeat("\xA5", 16);
        $owner = str_repeat("\x11", 32);
        $trailerExtra = '';
        if ($encrypt) {
            $p = -3904;
            $key = md5(self::PADDING.$owner.pack('V', $p & 0xFFFFFFFF).$id, true);
            for ($i = 0; $i < 50; $i++) {
                $key = md5(substr($key, 0, 16), true);
            }
            $key = substr($key, 0, 16);
            $objectKey = substr(md5($key."\x04\x00\x00\x00\x00", true), 0, 16);
            $stream = PdfDecryptor::rc4($objectKey, $stream);
            $objects[6] = '<</Filter/Standard/V 2/R 3/Length 128/P '.$p.'/O <'.bin2hex($owner).'>/U <'.str_repeat('00', 32).'>>>';
            $trailerExtra = '/Encrypt 6 0 R';
        }
        $objects[4] = '<</Filter/FlateDecode/Length '.strlen($stream).">>\nstream\n".$stream."\nendstream";

        $pdf = "%PDF-1.5\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "$num 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer<</Size '.(count($objects) + 1).'/Root 1 0 R'.$trailerExtra.'/ID[<'.bin2hex($id).'><'.bin2hex($id).">]>>\nstartxref\n$xref\n%%EOF\n";
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pila').'.pdf';
        file_put_contents($path, $content);

        return $path;
    }

    public function test_reads_encrypted_rotated_planilla_without_external_programs(): void
    {
        $extractor = new PdfTextExtractor(new PdfDecryptor);
        $text = $extractor->extractWithPhp($this->file($this->planillaPdf(encrypt: true)));

        $this->assertMatchesRegularExpression('/CC 1001\s+01\s+00/', $text);

        $rows = (new PilaPdfReader($extractor))->parse($text)['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('1001', $rows[0]['Identificación']);
        $this->assertSame('ANA MARIA GOMEZ', $rows[0]['Nombre']);
        $this->assertSame(30, $rows[0]['Días cotizados']);
        $this->assertSame(280200, $rows[0]['Aporte Pensión']);
        $this->assertSame(1750905, $rows[0]['IBC Salud']);
        $this->assertSame(70100, $rows[0]['Aporte Salud']);
        $this->assertSame(121900, $rows[0]['Aporte Riesgos']);
        $this->assertSame(70100, $rows[0]['Aporte Caja']);
        $this->assertSame(542300, $rows[0]['Total aportes']);
        $this->assertSame('COMFACOR', $rows[0]['Administradora caja']);
    }

    public function test_reads_unencrypted_pdf(): void
    {
        $text = (new PdfTextExtractor(new PdfDecryptor))->extractWithPhp($this->file($this->planillaPdf(encrypt: false)));

        $this->assertStringContainsString('$ 542.300', $text);
    }
}
