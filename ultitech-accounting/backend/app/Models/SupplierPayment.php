<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayment extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_supplier_payments';

    protected $fillable = [
        'uuid', 'company_id', 'book_id', 'supplier_id', 'payment_number', 'payment_date',
        'currency_code', 'amount', 'bank_account_id', 'status', 'reference',
        'posted_journal_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['payment_date' => 'date'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }
}
