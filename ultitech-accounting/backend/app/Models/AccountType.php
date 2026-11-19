<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountType extends Model
{
    protected $table = 'acc_account_types';

    protected $fillable = ['code', 'name', 'normal_balance', 'fs_classification'];
}
