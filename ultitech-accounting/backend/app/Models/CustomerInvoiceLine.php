<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerInvoiceLine extends Model
{
    protected $table = 'acc_customer_invoice_lines';

    protected $fillable = [
        'invoice_id', 'company_id', 'line_no', 'description', 'quantity', 'unit_price', 'line_total', 'revenue_account_id',
    ];
}
