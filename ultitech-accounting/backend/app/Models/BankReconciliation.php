<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class BankReconciliation extends Model {
  use BelongsToCompany;
  protected $table = "acc_bank_reconciliations";
  protected $fillable = ["company_id","bank_account_id","statement_id","reconciliation_date","gl_balance","statement_balance","unreconciled_total","status","completed_by","completed_at"];
  protected function casts(): array { return ["reconciliation_date"=>"date","completed_at"=>"datetime"]; }
}
