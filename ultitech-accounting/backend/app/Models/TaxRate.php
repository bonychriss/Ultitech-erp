<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxRate extends Model
{
    protected $table = 'acc_tax_rates';

    protected $fillable = [
        'jurisdiction_id',
        'tax_type_id',
        'code',
        'name',
        'rate_percent',
        'effective_from',
        'effective_to',
        'is_active',
        'authorized_by',
        'authorized_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
            'authorized_at' => 'datetime',
        ];
    }

    public function taxType(): BelongsTo
    {
        return $this->belongsTo(TaxType::class, 'tax_type_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }
}
