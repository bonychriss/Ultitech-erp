<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    public $timestamps = false;

    protected $table = 'acc_outbox_events';

    protected $fillable = [
        'company_id', 'event_type', 'aggregate_type', 'aggregate_id',
        'payload_json', 'status', 'available_at', 'processed_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
