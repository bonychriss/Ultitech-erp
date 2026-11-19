<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class FxRevaluation extends Model {
  use BelongsToCompany;
  protected $table = 'acc_fx_revaluations';
  protected $fillable = ['company_id','book_id','period_id','revaluation_date','functional_currency','total_gain','total_loss','journal_id','status','created_by'];
  protected function casts(): array { return ['revaluation_date'=>'date']; }
  public function lines(): HasMany { return $this->hasMany(FxRevaluationLine::class, 'fx_revaluation_id'); }
}
