<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxType extends Model
{
    protected $table = 'acc_tax_types';

    protected $fillable = ['code', 'name', 'direction'];
}
