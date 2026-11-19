<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashAccount extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_cash_accounts';

    protected $fillable = [
        'company_id', 'book_id', 'code', 'name', 'currency_code', 'gl_account_id', 'status',
    ];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }
}
