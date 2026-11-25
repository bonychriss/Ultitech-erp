<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxReturn extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_tax_returns';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_FILED = 'filed';

    public const STATUS_PAID = 'paid';

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
        'filing_reference',
        'filing_date',
        'payment_amount',
        'payment_date',
        'payment_bank_account_id',
        'payment_journal_id',
        'period_locked',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'submitted_at' => 'datetime',
            'filing_date' => 'date',
            'payment_date' => 'date',
            'period_locked' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TaxReturnLine::class, 'tax_return_id');
    }

    public function isLocked(): bool
    {
        return (bool) $this->period_locked
            || in_array(strtolower((string) $this->status), [self::STATUS_PAID], true);
    }
}
