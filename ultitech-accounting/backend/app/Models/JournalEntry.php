<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JournalEntry extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_journal_entries';

    protected $fillable = [
        'uuid', 'company_id', 'book_id', 'period_id', 'source_id', 'batch_id',
        'journal_number', 'journal_date', 'posting_date', 'description', 'reference',
        'status', 'approval_status', 'approval_request_id', 'currency_code', 'exchange_rate',
        'total_debit', 'total_credit', 'source_type', 'source_id_ref', 'source_hash',
        'posted_at', 'posted_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'posting_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'period_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(AccountingBook::class, 'book_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(JournalSource::class, 'source_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(JournalReversal::class, 'original_journal_id');
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
