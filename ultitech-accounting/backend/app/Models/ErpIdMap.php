<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErpIdMap extends Model
{
    protected $table = 'acc_erp_id_maps';

    protected $fillable = [
        'company_id', 'connection_id', 'entity_type', 'erp_id', 'local_id', 'meta_json',
    ];

    protected function casts(): array
    {
        return ['meta_json' => 'array'];
    }
}
