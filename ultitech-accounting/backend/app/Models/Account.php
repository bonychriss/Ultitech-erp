<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_accounts';

    protected $fillable = [
        'company_id', 'book_id', 'account_type_id', 'account_group_id', 'parent_id',
        'code', 'name', 'description', 'normal_balance', 'is_postable', 'is_system',
        'allow_manual_entry', 'currency_code', 'status', 'level', 'path',
    ];

    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
            'is_system' => 'boolean',
            'allow_manual_entry' => 'boolean',
        ];
    }

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
