<?php

namespace App\Services\Audit;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mantiene, por empresa y en la sesión del usuario, los tres archivos cargados
 * (principal y dos comparativos) hasta que se ejecute la auditoría.
 */
class FileWorkspace
{
    public const SLOTS = [
        'main' => ['number' => '01', 'title' => 'Archivo principal de nómina', 'desc' => 'Base de referencia · Liquidación completa', 'short' => 'Archivo principal'],
        'compare1' => ['number' => '02', 'title' => 'Archivo de comparación 1', 'desc' => 'Primer archivo para contrastar', 'short' => 'Comparación 1'],
        'compare2' => ['number' => '03', 'title' => 'Archivo de comparación 2', 'desc' => 'Segundo archivo para contrastar', 'short' => 'Comparación 2'],
    ];

    private const DISK = 'local';

    public function __construct(private SpreadsheetReader $reader) {}

    /** @return array<string, array<string,mixed>|null> */
    public function files(Company $company): array
    {
        $stored = session($this->key($company), []);

        return array_map(fn ($slot) => $stored[$slot] ?? null, array_combine(array_keys(self::SLOTS), array_keys(self::SLOTS)));
    }

    /**
     * Guarda el archivo y extrae sus encabezados.
     *
     * @return array{entry: array<string,mixed>, message: string}
     */
    public function store(Company $company, string $slot, UploadedFile $file): array
    {
        $this->remove($company, $slot);

        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs(
            "uploads/{$company->user_id}/{$company->id}",
            $slot.'-'.Str::uuid().($extension ? ".$extension" : ''),
            self::DISK
        );

        $entry = [
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'extension' => $extension,
            'supported' => false,
            'headers' => [],
            'rows' => 0,
        ];

        if (! SpreadsheetReader::supports($extension)) {
            $message = "{$entry['name']} se adjuntó correctamente. Su formato queda pendiente de extracción para la auditoría automática.";
        } else {
            try {
                $data = $this->reader->read(Storage::disk(self::DISK)->path($path), $extension);
                $entry['supported'] = true;
                $entry['headers'] = $data['headers'];
                $entry['rows'] = count($data['rows']);
                $message = "{$entry['name']}: {$entry['rows']} registros leídos.";
            } catch (Throwable $e) {
                $message = "{$entry['name']} se adjuntó, pero no se pudo leer automáticamente. Queda para revisión o conversión.";
            }
        }

        $this->put($company, $slot, $entry);

        return ['entry' => $entry, 'message' => $message];
    }

    /**
     * Reconstruye la homologación enviada por el formulario. Cada select llega como
     * fields[slot][índice] y el índice corresponde al encabezado del archivo en sesión
     * (así se admiten encabezados con cualquier carácter).
     *
     * @return array<string, array<string,string>>
     */
    public function mappingFromRequest(Company $company, Request $request): array
    {
        $result = [];
        foreach ($this->files($company) as $slot => $file) {
            $fields = $request->input("fields.$slot");
            if (! $file || ! $file['supported'] || ! is_array($fields)) {
                continue;
            }
            foreach ($file['headers'] as $i => $header) {
                $field = (string) ($fields[$i] ?? '');
                $result[$slot][$header] = array_key_exists($field, ColumnMapper::FIELDS) ? $field : '';
            }
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function rows(array $entry): array
    {
        return $this->reader->read(Storage::disk(self::DISK)->path($entry['path']), $entry['extension'])['rows'];
    }

    public function remove(Company $company, string $slot): void
    {
        $stored = session($this->key($company), []);
        if (isset($stored[$slot]['path'])) {
            Storage::disk(self::DISK)->delete($stored[$slot]['path']);
        }
        unset($stored[$slot]);
        session([$this->key($company) => $stored]);
    }

    private function put(Company $company, string $slot, array $entry): void
    {
        $stored = session($this->key($company), []);
        $stored[$slot] = $entry;
        session([$this->key($company) => $stored]);
    }

    private function key(Company $company): string
    {
        return "workspace.{$company->id}";
    }
}
