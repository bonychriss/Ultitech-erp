<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AccountOpening extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_account_openings';

    protected $fillable = [
        'company_id', 'book_id', 'account_id', 'period_id', 'debit', 'credit', 'posted_journal_id',
    ];
}
