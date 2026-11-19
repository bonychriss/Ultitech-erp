<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class ConsolidationRun extends Model {
  use BelongsToCompany;
  protected $table = 'acc_consolidation_runs';
  protected $fillable = ['group_id','company_id','as_of_date','result_json','created_by'];
  protected function casts(): array { return ['as_of_date'=>'date','result_json'=>'array']; }
}
