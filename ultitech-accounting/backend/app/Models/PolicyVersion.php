<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyVersion extends Model
{
    protected $table = 'acc_policy_versions';

    protected $fillable = [
        'policy_id',
        'version_no',
        'effective_from',
        'policy_json',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'policy_json' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AccountingPolicy::class, 'policy_id');
    }
}

