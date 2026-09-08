<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Existing ERP users table (read-only for this pilot). */
class ErpUser extends Model
{
    protected $table = 'users';

    public $timestamps = false;

    protected $fillable = [];

    public function suggestions(): HasMany
    {
        return $this->hasMany(DeveloperSuggestion::class, 'user_id');
    }
}
