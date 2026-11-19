<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    protected $table = 'acc_payment_allocations';

    protected $fillable = ['company_id', 'payment_id', 'bill_id', 'amount'];
}
