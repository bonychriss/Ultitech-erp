<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class JournalReversal extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_journal_reversals';

    protected $fillable = [
        'company_id', 'original_journal_id', 'reversal_journal_id', 'reversed_at', 'reversed_by', 'reason',
    ];

    protected function casts(): array
    {
        return ['reversed_at' => 'datetime'];
    }
}
