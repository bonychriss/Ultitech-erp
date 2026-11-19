<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierBillLine extends Model
{
    protected $table = 'acc_supplier_bill_lines';

    protected $fillable = [
        'bill_id', 'company_id', 'line_no', 'description', 'quantity', 'unit_price', 'line_total', 'expense_account_id',
    ];
}
