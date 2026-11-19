<?php
namespace App\Models;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ApprovalRequest extends Model {
  use BelongsToCompany;
  protected $table = 'acc_approval_requests';
  protected $fillable = ['company_id','book_id','workflow_id','entity_type','entity_id','status','current_step','requested_by','requested_at','resolved_at','notes'];
  protected function casts(): array { return ['requested_at'=>'datetime','resolved_at'=>'datetime']; }
  public function workflow(): BelongsTo { return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id'); }
  public function actions(): HasMany { return $this->hasMany(ApprovalAction::class, 'approval_request_id'); }
}