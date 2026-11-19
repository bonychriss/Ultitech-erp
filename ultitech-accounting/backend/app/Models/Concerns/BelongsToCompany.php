<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            $tenant = app(TenantContext::class);
            if ($tenant->company()) {
                $builder->where($builder->getModel()->getTable().'.company_id', $tenant->companyId());
            }
        });
    }
}
