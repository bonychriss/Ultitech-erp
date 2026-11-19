<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Budget extends Model {
  use BelongsToCompany;
  protected $table = 'acc_budgets';
  protected $fillable = ['company_id','book_id','fiscal_year_id','code','name','status','created_by'];
  public function lines(): HasMany { return $this->hasMany(BudgetLine::class, 'budget_id'); }
}
