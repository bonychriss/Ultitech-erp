<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Standard extends Model
{
    protected $table = 'acc_standards';

    protected $fillable = ['framework_id', 'code', 'title'];

    public function framework(): BelongsTo
    {
        return $this->belongsTo(Framework::class, 'framework_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StandardVersion::class, 'standard_id');
    }
}
