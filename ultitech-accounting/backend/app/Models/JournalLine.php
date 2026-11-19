<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_journal_lines';

    protected $fillable = [
        'journal_id', 'company_id', 'book_id', 'line_no', 'account_id', 'description',
        'debit', 'credit', 'currency_code', 'exchange_rate', 'functional_debit',
        'functional_credit', 'dimensions_json',
    ];

    protected function casts(): array
    {
        return ['dimensions_json' => 'array'];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
