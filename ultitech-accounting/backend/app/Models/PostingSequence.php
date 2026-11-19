<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PostingSequence extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_posting_sequences';

    protected $fillable = [
        'company_id', 'book_id', 'sequence_key', 'prefix', 'next_number', 'padding',
    ];
}
