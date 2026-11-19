<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $table = 'acc_company_settings';

    protected $fillable = ['company_id', 'settings_json'];

    protected function casts(): array
    {
        return ['settings_json' => 'array'];
    }
}
