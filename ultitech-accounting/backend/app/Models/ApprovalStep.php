<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ApprovalStep extends Model {
  protected $table = 'acc_approval_steps';
  protected $fillable = ['workflow_id','step_no','name','required_permission'];
}