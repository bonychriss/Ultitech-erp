<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BankTransaction extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_bank_transactions';

    protected $fillable = [
        'company_id', 'bank_account_id', 'transaction_date', 'description', 'debit', 'credit',
        'reference', 'source_type', 'source_id', 'journal_id', 'is_reconciled',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'is_reconciled' => 'boolean',
        ];
    }
}
