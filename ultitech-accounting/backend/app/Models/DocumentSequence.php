<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    use BelongsToCompany;

    protected $table = 'acc_document_sequences';

    protected $fillable = ['company_id', 'document_type', 'prefix', 'next_number'];
}
