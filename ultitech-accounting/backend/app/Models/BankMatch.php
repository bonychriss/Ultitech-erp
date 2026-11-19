<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class BankMatch extends Model {
  use BelongsToCompany;
  protected $table = "acc_bank_matches";
  protected $fillable = ["company_id","reconciliation_id","statement_line_id","bank_transaction_id","matched_amount"];
}
