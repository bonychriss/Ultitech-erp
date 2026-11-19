<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ApprovalWorkflow extends Model {
  use BelongsToCompany;
  protected $table = 'acc_approval_workflows';
  protected $fillable = ['company_id','code','name','entity_type','is_active'];
  protected function casts(): array { return ['is_active'=>'boolean']; }
  public function steps(): HasMany { return $this->hasMany(ApprovalStep::class, 'workflow_id'); }
}