<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StandardVersion extends Model
{
    protected $table = 'acc_standard_versions';

    protected $fillable = [
        'standard_id',
        'version_label',
        'effective_from',
        'effective_to',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
