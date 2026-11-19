<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxReconciliation extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_tax_reconciliations';

    protected $fillable = [
        'company_id',
        'tax_return_id',
        'gl_balance',
        'return_balance',
        'difference',
        'status',
        'notes',
    ];
}
