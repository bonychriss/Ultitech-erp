<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerReceipt extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_customer_receipts';

    protected $fillable = [
        'uuid', 'company_id', 'book_id', 'customer_id', 'receipt_number', 'receipt_date',
        'currency_code', 'amount', 'bank_account_id', 'cash_account_id', 'status',
        'reference', 'posted_journal_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['receipt_date' => 'date'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class, 'receipt_id');
    }
}
