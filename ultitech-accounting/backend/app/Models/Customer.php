<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_customers';

    protected $fillable = [
        'company_id', 'code', 'name', 'email', 'phone', 'tax_number', 'currency_code', 'status',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class, 'customer_id');
    }
}
