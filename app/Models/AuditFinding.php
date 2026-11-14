<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditFinding extends Model
{
    protected $fillable = [
        'code', 'type', 'employee', 'employee_id', 'concept', 'file_name',
        'main_value', 'compared_value', 'difference', 'description', 'suggestion', 'status',
    ];

    protected function casts(): array
    {
        return ['difference' => 'float'];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /** Formatea un valor (numérico o texto como "No existe") al estilo es-CO. */
    public static function display(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return is_numeric($value) ? self::amount((float) $value) : $value;
    }

    /** 1250000 → "1.250.000"; 72954.38 → "72.954,38". */
    public static function amount(float $value): string
    {
        $decimals = abs($value - round($value)) >= 0.005 ? 2 : 0;

        return number_format($value, $decimals, ',', '.');
    }
}
