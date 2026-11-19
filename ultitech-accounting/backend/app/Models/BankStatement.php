<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class BankStatement extends Model {
  use BelongsToCompany;
  protected $table = "acc_bank_statements";
  protected $fillable = ["company_id","bank_account_id","statement_number","statement_date","period_start","period_end","opening_balance","closing_balance","status","created_by"];
  protected function casts(): array { return ["statement_date"=>"date","period_start"=>"date","period_end"=>"date"]; }
  public function lines(): HasMany { return $this->hasMany(BankStatementLine::class, "statement_id"); }
}
