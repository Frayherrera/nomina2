<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Audit extends Model
{
    protected $fillable = [
        'company_id', 'period_id', 'company_name', 'period_label', 'files',
        'total', 'differences', 'missing', 'duplicates',
    ];

    protected function casts(): array
    {
        return ['files' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AuditFinding::class)->orderBy('id');
    }
}
