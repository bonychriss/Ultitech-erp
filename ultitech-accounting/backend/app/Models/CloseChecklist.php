<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class CloseChecklist extends Model {
  use BelongsToCompany;
  protected $table = 'acc_close_checklists';
  protected $fillable = ['company_id','book_id','close_type','period_id','fiscal_year_id','status','created_by'];
  public function items(): HasMany { return $this->hasMany(CloseChecklistItem::class, 'checklist_id'); }
}