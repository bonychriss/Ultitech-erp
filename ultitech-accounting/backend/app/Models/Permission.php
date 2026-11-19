<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $table = 'acc_permissions';

    protected $fillable = ['code', 'name', 'module'];
}
