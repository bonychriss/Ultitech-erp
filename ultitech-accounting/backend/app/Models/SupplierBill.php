<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierBill extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_supplier_bills';

    protected $fillable = [
        'uuid', 'company_id', 'book_id', 'supplier_id', 'bill_number', 'bill_date', 'due_date',
        'currency_code', 'subtotal', 'tax_total', 'total', 'amount_paid', 'balance_due',
        'status', 'description', 'posted_journal_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class, 'bill_id');
    }
}
