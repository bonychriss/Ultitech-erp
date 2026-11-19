<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    public $timestamps = false;

    protected $table = 'acc_idempotency_keys';

    protected $fillable = [
        'company_id', 'key_hash', 'request_path', 'response_code', 'response_body', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
