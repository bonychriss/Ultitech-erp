<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletBalanceWatch extends Model
{
    protected $table = 'acc_wallet_balance_watches';

    protected $fillable = [
        'company_id',
        'book_id',
        'wallet_type',
        'wallet_id',
        'last_balance',
        'direction',
        'delta',
        'changed_at',
        'seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_balance' => 'float',
            'delta' => 'float',
            'changed_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }
}
