<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxCode extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_tax_codes';

    protected $fillable = [
        'company_id',
        'jurisdiction_id',
        'tax_rate_id',
        'code',
        'name',
        'applies_to',
        'supply_category',
        'is_recoverable',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_recoverable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }
}
