<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxReturn extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_tax_returns';

    protected $fillable = [
        'company_id',
        'jurisdiction_id',
        'tax_type_id',
        'return_number',
        'period_start',
        'period_end',
        'output_tax',
        'input_tax',
        'net_tax',
        'status',
        'prepared_by',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TaxReturnLine::class, 'tax_return_id');
    }
}
