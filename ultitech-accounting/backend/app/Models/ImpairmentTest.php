<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class ImpairmentTest extends Model {
  use BelongsToCompany;
  protected $table = 'acc_impairment_tests';
  protected $fillable = ['company_id','book_id','asset_type','asset_id','test_date','carrying_amount','recoverable_amount','impairment_loss','journal_id','status','created_by','notes'];
  protected function casts(): array { return ['test_date'=>'date']; }
}
