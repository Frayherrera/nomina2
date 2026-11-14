<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Period extends Model
{
    protected $fillable = ['label', 'period_key'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
