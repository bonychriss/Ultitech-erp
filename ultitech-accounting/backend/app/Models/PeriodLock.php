<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PeriodLock extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_period_locks';

    protected $fillable = [
        'company_id', 'book_id', 'period_id', 'locked_at', 'locked_by', 'reason',
    ];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime'];
    }
}
