<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_fiscal_years';

    protected $fillable = ['company_id', 'name', 'start_date', 'end_date', 'status'];

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class, 'fiscal_year_id');
    }
}
