<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisclosureTemplate extends Model
{
    protected $table = 'acc_disclosure_templates';

    protected $fillable = ['framework_id', 'code', 'template_json', 'effective_from'];

    protected function casts(): array
    {
        return [
            'template_json' => 'array',
            'effective_from' => 'date',
        ];
    }
}
