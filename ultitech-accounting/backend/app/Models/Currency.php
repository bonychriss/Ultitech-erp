<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $table = 'acc_currencies';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'symbol', 'decimal_places', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
