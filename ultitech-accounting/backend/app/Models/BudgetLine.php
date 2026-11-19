<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BudgetLine extends Model {
  protected $table = 'acc_budget_lines';
  protected $fillable = ['budget_id','company_id','period_id','account_id','cost_center_id','amount'];
  public function account(): BelongsTo { return $this->belongsTo(Account::class, 'account_id'); }
  public function period(): BelongsTo { return $this->belongsTo(FiscalPeriod::class, 'period_id'); }
}
