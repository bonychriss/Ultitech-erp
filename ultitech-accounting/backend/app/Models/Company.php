<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $table = 'acc_companies';

    protected $fillable = [
        'uuid', 'code', 'legal_name', 'trading_name', 'country_code',
        'base_currency_code', 'timezone', 'status',
    ];

    public function settings(): HasOne
    {
        return $this->hasOne(CompanySetting::class, 'company_id');
    }

    public function books(): HasMany
    {
        return $this->hasMany(AccountingBook::class, 'company_id');
    }

    public function primaryBook(): HasOne
    {
        return $this->hasOne(AccountingBook::class, 'company_id')->where('is_primary', true);
    }
}
