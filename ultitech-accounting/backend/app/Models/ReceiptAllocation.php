<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptAllocation extends Model
{
    protected $table = 'acc_receipt_allocations';

    protected $fillable = ['company_id', 'receipt_id', 'invoice_id', 'amount'];
}
