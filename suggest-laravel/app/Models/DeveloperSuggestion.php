<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeveloperSuggestion extends Model
{
    protected $table = 'developer_suggestions';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'suggestion',
        'status',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(ErpUser::class, 'user_id');
    }
}
