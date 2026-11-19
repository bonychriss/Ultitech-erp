<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_exchange_rates';

    protected $fillable = [
        'company_id', 'from_currency', 'to_currency', 'rate_date', 'rate', 'source', 'created_by',
    ];
}
