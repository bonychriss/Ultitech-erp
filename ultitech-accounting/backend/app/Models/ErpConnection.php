<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpConnection extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_erp_connections';

    protected $fillable = [
        'company_id', 'code', 'name', 'connection_name', 'erp_database',
        'read_only', 'is_active', 'last_tested_at', 'last_test_status', 'last_test_message',
    ];

    protected function casts(): array
    {
        return [
            'read_only' => 'boolean',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function maps(): HasMany
    {
        return $this->hasMany(ErpIdMap::class, 'connection_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ErpSyncRun::class, 'connection_id');
    }
}
