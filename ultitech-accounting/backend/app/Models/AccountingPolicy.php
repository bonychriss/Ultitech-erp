<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingPolicy extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_accounting_policies';

    protected $fillable = ['company_id', 'book_id', 'code', 'name'];

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class, 'policy_id');
    }
}
