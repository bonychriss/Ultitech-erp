<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PeriodClose extends Model {
  use BelongsToCompany;
  protected $table = 'acc_period_closes';
  protected $fillable = ['company_id','book_id','period_id','checklist_id','status','closed_at','closed_by','notes'];
  protected function casts(): array { return ['closed_at'=>'datetime']; }
  public function checklist(): BelongsTo { return $this->belongsTo(CloseChecklist::class, 'checklist_id'); }
}
