<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
class ComplianceReport extends Model {
  use BelongsToCompany;
  protected $table = 'acc_compliance_reports';
  protected $fillable = ['company_id','book_id','report_number','as_of_date','result_json','passed_count','failed_count','status','generated_by'];
  protected function casts(): array { return ['as_of_date'=>'date','result_json'=>'array']; }
}