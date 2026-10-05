<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    protected $table = 'quotes';

    protected $fillable = [
        'quote_number',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_notes',
        'status',
        'sync_status',
        'ultitech_reference',
        'last_sync_attempt',
        'last_sync_error',
        'admin_notes',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class, 'quote_id');
    }
}
