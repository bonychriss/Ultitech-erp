<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ConsolidationGroup extends Model {
  use BelongsToCompany;
  protected $table = 'acc_consolidation_groups';
  protected $fillable = ['company_id','code','name','parent_book_id','reporting_currency'];
  public function members(): HasMany { return $this->hasMany(ConsolidationMember::class, 'group_id'); }
  public function runs(): HasMany { return $this->hasMany(ConsolidationRun::class, 'group_id'); }
}
