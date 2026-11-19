<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_suppliers';

    protected $fillable = [
        'company_id', 'code', 'name', 'email', 'phone', 'tax_number', 'currency_code', 'status',
    ];

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class, 'supplier_id');
    }
}
