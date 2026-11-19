<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_bank_accounts';

    protected $fillable = [
        'company_id', 'book_id', 'code', 'name', 'bank_name', 'account_number',
        'currency_code', 'gl_account_id', 'account_type', 'status',
    ];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }
}
