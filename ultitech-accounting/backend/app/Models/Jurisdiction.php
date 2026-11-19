<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Jurisdiction extends Model
{
    protected $table = 'acc_jurisdictions';

    protected $fillable = ['code', 'name', 'default_currency', 'metadata_json'];

    protected function casts(): array
    {
        return ['metadata_json' => 'array'];
    }
}
