<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalSource extends Model
{
    protected $table = 'acc_journal_sources';

    protected $fillable = ['code', 'name'];
}
