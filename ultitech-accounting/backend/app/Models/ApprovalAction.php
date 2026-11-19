<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApprovalAction extends Model {
  protected $table = 'acc_approval_actions';
  protected $fillable = ['approval_request_id','company_id','step_no','action','acted_by','comments','acted_at'];
  protected function casts(): array { return ['acted_at'=>'datetime']; }
}