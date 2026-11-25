<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxTransaction extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_tax_transactions';

    protected $fillable = [
        'company_id',
        'book_id',
        'tax_code_id',
        'tax_rate_id',
        'source_type',
        'source_id',
        'tax_date',
        'direction',
        'supply_category',
        'taxable_amount',
        'tax_amount',
        'rate_percent',
        'journal_id',
        'status',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'tax_date' => 'date',
        ];
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }
}
