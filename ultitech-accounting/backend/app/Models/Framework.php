<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Framework extends Model
{
    protected $table = 'acc_frameworks';

    protected $fillable = ['code', 'name', 'description'];

    public function standards(): HasMany
    {
        return $this->hasMany(Standard::class, 'framework_id');
    }
}
