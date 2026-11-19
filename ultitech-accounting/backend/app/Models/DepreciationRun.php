<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class DepreciationRun extends Model {
  use BelongsToCompany;
  protected $table = "acc_depreciation_runs";
  protected $fillable = ["company_id","book_id","period_id","run_date","total_amount","status","posted_journal_id","created_by"];
  protected function casts(): array { return ["run_date"=>"date"]; }
  public function lines(): HasMany { return $this->hasMany(DepreciationRunLine::class, "run_id"); }
}
