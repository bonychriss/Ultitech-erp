<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxReturnLine extends Model
{
    protected $table = 'acc_tax_return_lines';

    protected $fillable = [
        'tax_return_id',
        'company_id',
        'tax_transaction_id',
        'line_type',
        'taxable_amount',
        'tax_amount',
    ];
}
