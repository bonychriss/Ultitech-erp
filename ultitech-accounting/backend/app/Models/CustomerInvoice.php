<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerInvoice extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_customer_invoices';

    protected $fillable = [
        'uuid', 'company_id', 'book_id', 'customer_id', 'invoice_number', 'invoice_date', 'due_date',
        'currency_code', 'subtotal', 'tax_total', 'total', 'amount_paid', 'balance_due',
        'status', 'description', 'posted_journal_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerInvoiceLine::class, 'invoice_id');
    }
}
