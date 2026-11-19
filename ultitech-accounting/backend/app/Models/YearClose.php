<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class YearClose extends Model {
  use BelongsToCompany;
  protected $table = 'acc_year_closes';
  protected $fillable = ['company_id','book_id','fiscal_year_id','checklist_id','closing_journal_id','net_income','status','closed_at','closed_by','notes'];
  protected function casts(): array { return ['closed_at'=>'datetime']; }
}