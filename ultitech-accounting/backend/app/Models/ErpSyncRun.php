<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ErpSyncRun extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_erp_sync_runs';

    protected $fillable = [
        'company_id', 'book_id', 'connection_id', 'run_number', 'mode', 'scopes_json',
        'stats_json', 'status', 'message', 'started_by', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes_json' => 'array',
            'stats_json' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
